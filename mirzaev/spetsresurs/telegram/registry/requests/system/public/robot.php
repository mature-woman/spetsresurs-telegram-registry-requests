<?php

// Фреймворк ArangoDB
use mirzaev\arangodb\connection,
	mirzaev\arangodb\collection,
	mirzaev\arangodb\document;

// Библиотека для ArangoDB
use ArangoDBClient\Document as _document,
	ArangoDBClient\Cursor,
	ArangoDBClient\Statement as _statement;

// Фреймворк Telegram
use Zanzara\Zanzara;
use Zanzara\Context;
use Zanzara\Config;

require __DIR__ . '/../../../../../../../vendor/autoload.php';

$arangodb = new connection(require __DIR__ . '/../settings/arangodb.php');

/**
 * Авторизация
 *
 * @param string $id Идентификатор Telegram
 *
 * @return _document|null|false (инстанция аккаунта, если подключен и авторизован; null, если не подключен; false, если подключен но неавторизован)
 */
function authorization(string $id): _document|null|false
{
	global $arangodb;

	if (collection::init($arangodb->session, 'telegram'))
		if (
			($telegram = collection::search($arangodb->session, sprintf("FOR d IN telegram FILTER d.id == '%s' RETURN d", $id)))
			|| $telegram = collection::search(
				$arangodb->session,
				sprintf(
					"FOR d IN telegram FILTER d._id == '%s' RETURN d",
					document::write($arangodb->session,	'telegram', ['id' => $id, 'status' => 'inactive'])
				)
			)
		)
			if ($telegram->number === null) return null;
			else if (
				$telegram->status === 'active'
				&& collection::init($arangodb->session, 'workers')
				&& $worker = collection::search(
					$arangodb->session,
					sprintf(
						"FOR d IN workers LET e = (FOR e IN connections FILTER e._to == '%s' RETURN e._from)[0] FILTER d._id == e RETURN d",
						$telegram->getId()
					)
				)
			) return $worker;
			else return false;
		else  throw new exception('Не удалось найти или создать аккаунт');
	else throw new exception('Не удалось инициализировать коллекцию');

	return false;
}

function registration(string $id, string $number): bool
{
	global $arangodb;

	if (collection::init($arangodb->session, 'telegram')) {
		if ($telegram = collection::search($arangodb->session, sprintf("FOR d IN telegram FILTER d.id == '%s' RETURN d", $id))) {
			// Найден аккаунт

			// Запись номера
			$telegram->number = $number;
			if (!document::update($arangodb->session, $telegram)) return false;
		} else if (!collection::search(
			$arangodb->session,
			sprintf(
				"FOR d IN telegram FILTER d._id == '%s' RETURN d",
				document::write($arangodb->session,	'telegram', ['id' => $id, 'status' => 'inactive', 'number' => $number])
			)
		)) return false;

		// Инициализация ребра: workers -> telegram
		if (
			collection::init($arangodb->session, 'workers')
			&& ($worker = collection::search(
				$arangodb->session,
				sprintf(
					"FOR d IN workers FILTER d.phone == '%d' RETURN d",
					$telegram->number
				)
			))
			&& collection::init($arangodb->session, 'connections', true)
			&& (collection::search(
				$arangodb->session,
				sprintf(
					"FOR d IN connections FILTER d._from == '%s' && d._to == '%s' RETURN d",
					$worker->getId(),
					$telegram->getId()
				)
			)
				?? collection::search(
					$arangodb->session,
					sprintf(
						"FOR d IN connections FILTER d._id == '%s' RETURN d",
						document::write(
							$arangodb->session,
							'connections',
							['_from' => $worker->getId(), '_to' => $telegram->getId()]
						)
					)
				))
		) {
			// Инициализировано ребро: workers -> telegram

			// Активация
			$telegram->status = 'active';
			return document::update($arangodb->session, $telegram);
		}
	} else throw new exception('Не удалось инициализировать коллекцию');

	return false;
}

function generateAuthenticationKeyboard(): array
{
	return [
		'reply_markup' =>	[
			'keyboard' => [
				[
					['text' => '🔐 Аутентификация', 'request_contact' => true]
				]
			],
			'resize_keyboard' => true
		]
	];
}

function generateMenu(Context $ctx): void
{
	if ($worker = authorization($ctx->getMessage()?->getFrom()?->getId() ?? $ctx->getCallbackQuery()->getFrom()->getId())) {
		// Успешная авторизация

		$ctx->sendMessage('👋 Здравствуйте, ' . $worker->name, [
			'reply_markup' => [
				'inline_keyboard' => [
					[
						['text' => '🔍 Активные заявки', 'callback_data' => 'search']
					]
				],
				'remove_keyboard' => true
			]
		]);
	}
}

function requests(int $amount = 5, int $page = 1): Cursor
{
	global $arangodb;

	// Фильтрация номера страницы
	if ($page < 1) $page = 1;

	// Инициализация номера страницы для вычислний
	--$page;

	// Инициализация сдвига
	$offset = $page === 0 ? 0 : $page * $amount;

	return (new _statement(
		$arangodb->session,
		[
			'query' => sprintf(
				"FOR d IN works FILTER d.worker == null && d.confirmed != 'да' SORT d.created DESC LIMIT %d, %d RETURN d",
				$offset,
				$amount + $offset
			),
			"batchSize" => 1000,
			"sanitize"  => true
		]
	))->execute();
}

function generateEmojis(): string
{
	return '&#' . hexdec(trim(array_rand(file(__DIR__ . '/../emojis.txt')))) . ';';
}

function requests_next(Context $ctx): void
{
	$ctx->getChatDataItem('requests_page')->then(function ($page) use ($ctx) {
		$ctx->setChatDataItem('requests_page', ($page ?? 1) + 1)->then(function () use ($ctx) {
			search($ctx);
		});
	});
}

function requests_previous(Context $ctx): void
{
	$ctx->getChatDataItem('requests_page')->then(function ($page) use ($ctx) {
		$ctx->setChatDataItem('requests_page', ($page ?? 2) - 1)->then(function () use ($ctx) {
			search($ctx);
		});
	});
}

function request_choose(Context $ctx): void
{
	global $arangodb;

	if (($worker = authorization($ctx->getCallbackQuery()->getFrom()->getId())) instanceof _document) {
		// Авторизован

		// Инициализация ключа инстанции works в базе данных
		preg_match('/^#(\d+)\n/', $ctx->getCallbackQuery()->getMessage()->getText(), $matches);
		$_key = $matches[1];

		// Инициализация инстанции works в базе данных (выбранного задания)
		$work = collection::search($arangodb->session, sprintf("FOR d IN works FILTER d._key == '%s' RETURN d", $_key));

		// Запись о том, что задание подтверждено (в будущем здесь будет отправка на потдверждение модераторам)
		$work->confirmed = 'да';

		// Запись о том, что необходимо перенести изменения в Google Sheets
		$work->transfer_to_sheets = 'да';

		// Запись идентификатора Google Sheets нового сотрудника
		$work->worker = $worker->id;

		if (document::update($arangodb->session, $work)) {
			// Записано обновление в базу данных

			if (collection::search(
				$arangodb->session,
				sprintf(
					"FOR d IN readinesses FILTER d._id == '%s' RETURN d",
					document::write($arangodb->session, 'readinesses', ['_from' => $worker->getId(), '_to' => $work->getId()])
				)
			)) {
				// Записано ребро: worker -> work (принятие заявки)

				$ctx->sendMessage("✅ *Заявка принята:* \#$_key", ['reply_markup' =>	['remove_keyboard' => true]])->then(function () use ($ctx) {
					generateMenu($ctx);
				});
			}
		}
	}
}

function search(Context $ctx): void
{
	global $arangodb;

	if (authorization($ctx->getMessage()?->getFrom()?->getId() ?? $ctx->getCallbackQuery()->getFrom()->getId()) instanceof _document) {
		// Авторизован

		$ctx->getChatDataItem('requests_page')->then(function ($page) use ($ctx, $arangodb) {
			// Найдена текущая страница

			// Значение страницы по умолчанию
			if (empty($page)) {
				$page = 1;
				$ctx->setChatDataItem('requests_page', 1);
			}

			// Поиск заявок из базы данных
			$requests = requests(6, $page);

			// Подсчёт количества прочитанных заявок из базы данных
			$count = $requests->getCount();

			// Проверка существования избытка
			$excess = $count % 6 === 0;

			// Обрезка заявок до размера страницы
			$requests = array_slice($requests->getAll(), 0, 5);

			if ($count === 0) $ctx->sendMessage('📦 *Заявок нет*');
			else {
				// Найдены заявки

				foreach ($requests as $i => $request) {
					// Перебор найденных заявок
	
					if (($market = collection::search(
						$arangodb->session,
						sprintf(
							"FOR d IN markets LET e = (FOR e IN requests FILTER e._to == '%s' RETURN e._from)[0] FILTER d._id == e RETURN d",
							$request->getId()
						)
					)) instanceof _document) {
						// Найден магазин	

						$ctx->getChatDataItem("request_$i")->then(function ($message) use ($ctx) {
							// Удаление предыдущего сообщения на этой позиции
							$ctx->deleteMessage($message->getChat()->getId(), $message->getMessageId());
						});

						// Генерация эмодзи
						/* $emoji = generateEmojis(); */

						// Отправка сообщения
						$ctx->sendMessage(
							preg_replace(
								'/([._\-()!#])/',
								'\\\$1',
								"*#{$request->getKey()}*\n" . $request->date['converted'] . " (" . $request->start['converted'] . " - " . $request->end['converted'] . ")\n\n*Город:* $market->city\n*Адрес:* $market->address\n*Работа:* \"$request->work\""
							),
							[
								'reply_markup' => [
									'inline_keyboard' => [
										[
											['text' => '✅ Отправить запрос', 'callback_data' => 'request_choose']
										]
									]
								]
							]
						)->then(function ($message) use ($ctx, $requests, $i, $page, $excess) {
							// Запись сообщения в кеш (на случай необходимости его удаления при смене страницы)
							$ctx->setChatDataItem("request_$i", $message)->then(function () use ($ctx, $requests, $i, $page, $excess) {
								if ($i === array_key_last($requests)) {
									// Удаление предыдущего меню 
									$ctx->getChatDataItem("request_menu")->then(function ($message) use ($ctx) {
										$ctx->deleteMessage($message->getChat()->getId(), $message->getMessageId());
									});

									// Инициализация буфера для меню поиска
									$keyboard = [];

									// Генерация кнопки: "Предыдущая страница"
									if ($page > 1) $keyboard[] = ['text' => 'Назад ⬅️', 'callback_data' => 'requests_previous'];

									// Генерация кнопки: "Следующая страница"
									if ($excess) $keyboard[] = ['text' => '➡️ Вперёд', 'callback_data' => 'requests_next'];

									// Отправка меню
									$ctx->sendMessage('🔍 Выберите заявку', [
										'reply_markup' => [
											'inline_keyboard' => [
												$keyboard
											]
										]
									])->then(function ($message) use ($ctx) {
										// Запись сообщения в кеш (на случай необходимости его удаления при смене страницы)
										$ctx->setChatDataItem("request_menu", $message);
									});
								}
							});
						});
					}
				}
			}
		});
	}
}

$config = new Config();
$config->setParseMode(Config::PARSE_MODE_MARKDOWN);

$bot = new Zanzara(require(__DIR__ . '/../settings/key.php'), $config);

$stop = false;

$bot->onUpdate(function (Context $ctx) use (&$stop): void {
	$message = $ctx->getMessage();

	if (
		isset($message)
		&& ($contact = $message->getContact())
		&& $contact->getUserId() === $message->getFrom()->getId()
	) {
		// Передан контакт со своими данными (подразумевается второй шаг аутентификации и запуск регистрации)

		// Запуск регистрации
		if (registration($contact->getUserId(), $contact->getPhoneNumber())) {
			// Успешная регистрация

			$ctx->sendMessage('✅ *Аккаунт подключен*', ['reply_markup' =>	['remove_keyboard' => true]])->then(function () use ($ctx) {
				generateMenu($ctx);
			});

			$stop = true;
		} else $ctx->sendMessage('⛔ *Вы не авторизованы*', generateAuthenticationKeyboard());
	} else if ($message?->getText() !== '🔐 Аутентификация' && !authorization($message?->getFrom()?->getId() ?? $ctx->getCallbackQuery()->getFrom()->getId())) {
		$ctx->sendMessage('⛔ *Вы не авторизованы*', generateAuthenticationKeyboard());

		$stop = true;
	}
});

$bot->onCommand('start', function (Context $ctx) use ($stop): void {
	if ($stop) return;
	generateMenu($ctx);
});

$bot->onCommand('search', fn ($ctx) => search($ctx));
$bot->onCbQueryData(['search'], fn ($ctx) => search($ctx));
$bot->onCbQueryData(['requests_next'], fn ($ctx) => requests_next($ctx));
$bot->onCbQueryData(['requests_previous'], fn ($ctx) => requests_previous($ctx));
$bot->onCbQueryData(['request_choose'], fn ($ctx) => request_choose($ctx));

$bot->run();

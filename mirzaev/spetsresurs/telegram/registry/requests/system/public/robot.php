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

/* ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1); */

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

	if (collection::init($arangodb->session, 'telegram')) {
		if ($telegram = collection::search($arangodb->session, sprintf("FOR d IN telegram FILTER d.id == '%s' RETURN d", $id))) {
			if ($telegram->number === null) return null;
			else if (
				$telegram->active
				&& collection::init($arangodb->session, 'account')
				&& $account = collection::search(
					$arangodb->session,
					sprintf(
						"FOR d IN account FILTER d.number == '%s' RETURN d",
						$telegram->number,
						$telegram->getId()
					)
				)
			) return $account;
			else return false;
		}
	} else throw new exception('Не удалось инициализировать коллекцию');

	return false;
}

/**
 * Сотрудник
 *
 * @param string $id Идентификатор аккаунта
 *
 * @return _document|null|false (инстанция аккаунта, если подключен и авторизован; null, если не подключен; false, если подключен но неавторизован)
 */
function worker(string $id): _document|null|false
{
	global $arangodb;

	return collection::search(
		$arangodb->session,
		sprintf(
			<<<'AQL'
				FOR d IN worker
					LET e = (
						FOR e IN account_edge_worker
							FILTER e._from == '%s'
							SORT e.created DESC, e._key DESC
							LIMIT 1
							RETURN e
					)
					FILTER d._id == e[0]._to && d.active == true
					SORT d.created DESC, d._key DESC
					LIMIT 1
					RETURN d
			AQL,
			$id
		)
	);
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
		} else if (
			$number === null
			|| !$telegram = collection::search(
				$arangodb->session,
				sprintf(
					"FOR d IN telegram FILTER d._id == '%s' RETURN d",
					document::write($arangodb->session,	'telegram', ['id' => $id, 'active' => false, 'number' => $number])
				)
			)
		) return false;

		// Инициализация ребра: account -> telegram
		if (
			collection::init($arangodb->session, 'account')
			&& ($account = collection::search(
				$arangodb->session,
				sprintf(
					"FOR d IN account FILTER d.number == '%d' RETURN d",
					$telegram->number
				)
			))
			&& collection::init($arangodb->session, 'connection', true)
			&& (collection::search(
				$arangodb->session,
				sprintf(
					"FOR d IN connection FILTER d._from == '%s' && d._to == '%s' RETURN d",
					$account->getId(),
					$telegram->getId()
				)
			)
				?? collection::search(
					$arangodb->session,
					sprintf(
						"FOR d IN connection FILTER d._id == '%s' RETURN d",
						document::write(
							$arangodb->session,
							'connection',
							['_from' => $account->getId(), '_to' => $telegram->getId()]
						)
					)
				))
		) {
			// Инициализировано ребро: account -> telegram

			// Активация
			$telegram->active = true;
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
	if ($account = authorization($ctx->getMessage()?->getFrom()?->getId() ?? $ctx->getCallbackQuery()->getFrom()->getId())) {
		// Успешная авторизация

		$ctx->sendMessage('👋 Здравствуйте, ' . preg_replace('/([._\-()!#])/', '\\\$1', $account->name['first']), [
			'reply_markup' => [
				'inline_keyboard' => [
					[
						['text' => '🔍 Активные заявки', 'callback_data' => 'day']
					]
				],
				'remove_keyboard' => true
			]
		])->then(function ($message) use ($ctx) {
			$ctx->setChatDataItem("menu", $message);
		});
	}
}

/**
 * Прочитать заявки из ArangoDB
 *
 * @param int $amount Количество
 * @param ?string $date За какую дату (unixtime)
 * @param int $page Страница
 *
 * @return Cursor
 */
function requests(int $amount = 5, ?string $date = null, int $page = 1): Cursor
{
	global $arangodb;

	// Инициализация значения даты по умолчанию
	$date ??= time();

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
				// d.date < %s там специально, не менять на <=
				"FOR d IN task FILTER ((d.date >= %s && d.date < %s && d.start >= '05:00') || (d.date >= %s && d.date < %s && d.start < '05:00')) && d.worker == null && d.market != null && d.confirmed != true && d.published == true && d.completed != true SORT d.created DESC, d._key DESC LIMIT %d, %d RETURN d",
				$from = (new DateTime("@$date"))->setTime(0, 0)->format('U'),
				$to = (new DateTime("@$date"))->modify('+1 day')->setTime(0, 0)->format('U'),
				$to,
				(new DateTime("@$date"))->modify('+2 day')->setTime(0, 0)->format('U'),
				$offset,
				$amount + $offset - ($page > 0)
			),
			"batchSize" => 1000,
			"sanitize"	=> true
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
		$ctx->setChatDataItem('requests_page', ($page ?? 1) + 1)->then(function () use ($ctx, $page) {
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

	if (($account = authorization($ctx->getCallbackQuery()->getFrom()->getId())) instanceof _document) {
		// Авторизован

		// Инициализация ключа инстанции task в базе данных
		preg_match('/\->\s#(\d+)\n/', $ctx->getCallbackQuery()->getMessage()->getText(), $matches);
		$_key = $matches[1];

		// Инициализация инстанции task в базе данных (выбранного задания)
		$task = collection::search($arangodb->session, sprintf("FOR d IN task FILTER d._key == '%s' && d.published == true && d.completed != true RETURN d", $_key));

		if ($worker = worker($account->getId())) {
			// Найден сотрудник

			// Запись идентификатора нового сотрудника
			$task->worker = $worker->id;

			// Снятие с публикации
			$task->published = false;

			if (document::update($arangodb->session, $task)) {
				// Записано обновление в базу данных

				$ctx->getChatDataItem("request_all")->then(function ($requests = []) use ($ctx, $_key) {
					// Удаление сообщений связанных с запросом
					foreach ($requests ?? [] as $_message) $ctx->deleteMessage($_message->getChat()->getId(), $_message->getMessageId());
					$ctx->setChatDataItem("request_all", []);

					$ctx->sendMessage("✅ *Заявка принята:* \#$_key", ['reply_markup' =>	['remove_keyboard' => true]])->then(function () use ($ctx) {
						generateMenu($ctx);
					});

					// End of the process
					$ctx->endConversation();
				});
			} else $ctx->sendMessage("❎ *Не удалось принять заявку:* \#$_key", ['reply_markup' =>	['remove_keyboard' => true]])->then(function () use ($ctx) {
				generateMenu($ctx);
			});
		} else $ctx->sendMessage("❎ *Не удалось принять заявку:* \#$_key", ['reply_markup' =>	['remove_keyboard' => true]])->then(function () use ($ctx) {
			generateMenu($ctx);
		});
	}
}

function day(Context $ctx): void
{
	if (authorization($ctx->getMessage()?->getFrom()?->getId() ?? $ctx->getCallbackQuery()->getFrom()->getId()) instanceof _document) {
		// Авторизован

		// Инициализация буфера клавиатуры
		$keyboard = [];

		// Генерация кнопок с выбором даты
		for ($i = 1, $r = 0; $i < 15; ++$i) $keyboard[$i > 4 * ($r + 1) ? ++$r : $r][] = ['text' => ($date = (new DateTime)->modify("+$i day"))->format('d.m.Y'), 'callback_data' => $date->format('U')];

		$ctx->setChatDataItem('requests_page', 1)->then(function () use ($ctx, $keyboard) {
			// Отправка меню
			$ctx->sendMessage('📅 Выберите дату', [
				'reply_markup' => [
					'inline_keyboard' => $keyboard
				]
			])->then(function ($message) use ($ctx) {
				$ctx->getChatDataItem("menu")->then(function ($message) use ($ctx) {
					// Удаление главного меню
					if ($message) $ctx->deleteMessage($message->getChat()->getId(), $message->getMessageId());
					$ctx->setChatDataItem("menu", null);
				});

				// Запись сообщения в кеш (на случай необходимости его удаления при смене страницы)
				$ctx->setChatDataItem("request_day", $message);
			});
		});

		$ctx->nextStep("search");
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

			$generate = function ($date) use ($ctx, $page, $arangodb) {
				// Поиск заявок в ArangoDB
				$tasks = requests(4, (string) $date, $page);

				// Подсчёт количества прочитанных заявок из базы данных
				$count = $tasks->getCount();

				// Проверка существования избытка
				$excess = $count > 3;

				// Обрезка заявок до размера страницы (3 заявки на 1 странице)
				$tasks = array_slice($tasks->getAll(), 0, 3);

				if ($count === 0) {
					$ctx->sendMessage('📦 *Заявок нет*')->then(function ($message) use ($ctx) {
						$ctx->getChatDataItem("request_all")->then(function ($requests = []) use ($ctx, $message) {
							// Удаление сообщений связанных с запросом
							foreach ($requests ?? [] as $_message) $ctx->deleteMessage($_message->getChat()->getId(), $_message->getMessageId());
							$ctx->setChatDataItem("request_all", $requests = [$message]);
						});
					});
				} else {
					// Найдены заявки

					$ctx->getChatDataItem("request_day")->then(function ($message) use ($ctx, $arangodb, $tasks, $page, $excess) {
						// Удаление предыдущего меню с выбором даты
						if ($message) $ctx->deleteMessage($message->getChat()->getId(), $message->getMessageId());
						$ctx->setChatDataItem("request_day", null)->then(function () use ($ctx, $arangodb, $tasks, $page, $excess) {
							$ctx->getChatDataItem("request_all")->then(function ($requests = []) use ($ctx, $arangodb, $tasks, $excess, $page) {
								// Удаление сообщений связанных с запросом
								foreach ($requests ?? [] as $_message) $ctx->deleteMessage($_message->getChat()->getId(), $_message->getMessageId());
								$ctx->setChatDataItem("request_all", [])->then(function () use ($ctx, $arangodb, $tasks, $excess, $page) {
									foreach ($tasks as $i => $task) {
										// Перебор найденных заявок

										if (($market = collection::search(
											$arangodb->session,
											sprintf(
												"FOR d IN market FILTER d.id == '%s' RETURN d",
												$task->market
											)
										)) instanceof _document) {
											// Найден магазин	
											$ctx->getChatDataItem("request_$i")->then(function ($message) use ($ctx, $task, $market, $tasks, $i, $page, $excess) {
												// Удаление предыдущего сообщения на этой позиции
												if ($message) $ctx->deleteMessage($message->getChat()->getId(), $message->getMessageId());
												$ctx->setChatDataItem("request_$i", null)->then(function () use ($ctx, $task, $market, $tasks, $i, $page, $excess) {
													// Генерация эмодзи
													/* $emoji = generateEmojis(); */

													// Отправка сообщения
													$ctx->sendMessage(
														preg_replace(
															'/([._\-()!#])/',
															'\\\$1',
															"*#$task->market* -\> *#{$task->getKey()}*\n" . (new DateTime('@' . $task->date))->format('d.m.Y') . " (" . $task->start . " - " . $task->end . ")\n\n*Город:* $market->city\n*Адрес:* $market->address\n*Работа:* $task->work" . (mb_strlen($task->description) > 0 ? "\n\n$task->description" : '')
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
													)->then(function ($message) use ($ctx, $tasks, $i, $page, $excess) {
														// Запись сообщения в кеш (на случай необходимости его удаления при смене страницы)
														$ctx->setChatDataItem("request_$i", $message)->then(function () use ($ctx, $message, $tasks, $i, $page, $excess) {
															$ctx->getChatDataItem("request_all")->then(function ($requests = []) use ($ctx, $message, $tasks, $i, $page, $excess) {
																$ctx->setChatDataItem("request_all", $requests = ($requests ?? []) + [count($requests) => $message])->then(function () use ($ctx, $tasks, $i, $page, $excess) {
																	if ($i === array_key_last($tasks)) {
																		// End of the process
																		$ctx->endConversation();

																		// Удаление предыдущего меню
																		$ctx->getChatDataItem("request_menu")->then(function ($message) use ($ctx, $page, $excess) {
																			if ($message) $ctx->deleteMessage($message->getChat()->getId(), $message->getMessageId());
																			$ctx->setChatDataItem("request_menu", null)->then(function () use ($ctx, $page, $excess) {
																				// Инициализация буфера для меню поиска
																				$keyboard = [];

																				// Генерация кнопки: "Предыдущая страница"
																				if ($page > 1) $keyboard[] = ['text' => 'Назад', 'callback_data' => 'requests_previous'];

																				// Генерация кнопки: "Отображённая страница"
																				$keyboard[] = ['text' => $page, 'callback_data' => 'requests_current'];

																				// Генерация кнопки: "Следующая страница"
																				if ($excess) $keyboard[] = ['text' => 'Вперёд', 'callback_data' => 'requests_next'];

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
																			});
																		});
																	}
																});
															});
														});
													});
												});
											});
										}
									}
								});
							});
						});
					});
				}
			};

			// Инициализация даты и генерация
			$ctx->getChatDataItem('requests_date')->then(function ($old) use ($ctx, $generate) {
				$new = $ctx->getCallbackQuery()->getData();
				if ($new === (string) (int) $new && $new <= PHP_INT_MAX && $new >= ~PHP_INT_MAX) $ctx->setChatDataItem('requests_date', $new)->then(fn () => $generate($new));
				else $generate($old);
			});
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
	$ctx->getChatDataItem("request_all")->then(function ($requests = []) use ($ctx) {
		// Удаление сообщений связанных с запросом
		foreach ($requests ?? [] as $_message) $ctx->deleteMessage($_message->getChat()->getId(), $_message->getMessageId());
		$ctx->setChatDataItem("request_all", []);
	});

	$ctx->getChatDataItem("menu")->then(function ($message) use ($ctx) {
		// Удаление главного меню
		if ($message) $ctx->deleteMessage($message->getChat()->getId(), $message->getMessageId());
		$ctx->setChatDataItem("menu", null);
	});

	$ctx->getChatDataItem("request_day")->then(function ($message) use ($ctx) {
		// Удаление меню выбора даты
		if ($message) $ctx->deleteMessage($message->getChat()->getId(), $message->getMessageId());
		$ctx->setChatDataItem("request_day", null);
	});

	generateMenu($ctx);
});

$bot->onCbQueryData(['requests_next'], fn ($ctx) => requests_next($ctx));
$bot->onCbQueryData(['requests_previous'], fn ($ctx) => requests_previous($ctx));
$bot->onCbQueryData(['request_choose'], fn ($ctx) => request_choose($ctx));
$bot->onCommand('day', fn ($ctx) => day($ctx));
$bot->onCbQueryData(['day'], fn ($ctx) => day($ctx));

$bot->run();

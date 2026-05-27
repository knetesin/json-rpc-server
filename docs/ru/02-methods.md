# 02 — Методы

Метод — это класс, который:

1. Несёт `#[Rpc\Method('name')]`
2. Реализует `__invoke()` — любая callable-сигнатура, любой serializable-возврат

Compiler pass находит их через auto-configuration. Никакой ручной регистрации
сервисов, никакого центрального реестра, который надо синхронизировать.

## Атрибут

```php
#[Rpc\Method(
    name: 'user.getByEmail',
    roles: ['ROLE_USER'],                      // см. главу о безопасности
    rolesMatch: RoleMatch::Any,                // any | all
    allowPositionalDto: false,                 // см. главу о параметрах
    rejectUnknown: true,                       // см. главу о параметрах
    deprecated: 'Use user.find instead.',      // помечает deprecated
    description: 'Looks up a user by email.',  // human-readable; идёт в MCP
    outputSchema: UserDto::class,              // опционально: см. главы MCP / OpenRPC
)]
```

Все поля кроме `name` опциональны. `null` падает к дефолтам бандла
(например, `params.allow_positional_dto`).

## Handler

```php
#[Rpc\Method('user.getByEmail')]
final class GetUserByEmail
{
    public function __construct(
        // Инжектим сервисы как любой Symfony-сервис.
        private readonly UserRepository $users,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(GetUserByEmailRequest $req, Context $ctx): array
    {
        $user = $this->users->findOneByEmail($req->email)
            ?? throw new NotFoundException("No user for {$req->email}");

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
        ];
    }
}
```

Handlers — non-shared сервисы; каждый запрос получает свежий экземпляр.
Безопасно для long-running workers (RoadRunner, Octane, Swoole): нет утечек
state'а между юзерами.

## Возвращаемые значения

Dispatcher нормализует результат через Symfony `SerializerInterface` перед
отдачей клиенту. Это значит, что handlers могут возвращать:

- Обычные массивы / скаляры / nulls — отдаются как есть
- DTO — денормализуются в массивы через настроенные нормализаторы
- Doctrine entities — сериализуются по вашим serialization groups
- Что угодно с `JsonSerializable`

Нормализованная форма — это **то же**, что попадает в кэш и что несут события.
Listeners видят одну и ту же форму независимо от того, hit это из кэша или
свежий вызов.

Streaming-методы нормализуют построчно — см. [Стриминг](./07-streaming.md).

## Batch-запросы

`/rpc` принимает и единичный объект, и массив объектов по спеке JSON-RPC 2.0:

```json
[
  {"jsonrpc":"2.0","method":"math.add","params":[1,2],"id":1},
  {"jsonrpc":"2.0","method":"math.add","params":[3,4],"id":2}
]
```

Возвращает массив ответов в том же порядке. Notifications (без `id`)
выполняются, но не дают entry в ответе. Если batch целиком из notifications —
HTTP-ответ `204 No Content`.

### Batch выполняется последовательно по умолчанию

Dispatcher проходит массив по порядку и каждый item доводит до конца до
следующего — всё в одном PHP-процессе. Batch из N элементов занимает
примерно `сумма длительностей хендлеров`, не `max`. Экономится **сетевой
overhead** (один HTTP-запрос, один парсер, одна аутентификация), а не время
работы хендлеров.

Для реального параллелизма клиент должен слать N отдельных HTTP-запросов
параллельно (например, через [json-rpc-client's `callAsync`](https://github.com/knetesin/json-rpc-client))
— PHP-FPM / RoadRunner / Swoole тогда раскидают каждый запрос на отдельного
воркера и они действительно пойдут одновременно.

### Opt-in: параллельный batch через loopback fan-out

По спеке JSON-RPC 2.0 §6, сервер _может_ обрабатывать batch в любом порядке и
с любой степенью параллелизма. Бандл везёт opt-in реализацию: каждый item
batch'а отсылается обратно к самому себе отдельным HTTP-запросом, и worker
pool обрабатывает хендлеры параллельно.

```yaml
json_rpc_server:
    parallel_batch:
        enabled: true               # выключено по умолчанию
        max_concurrency: 3          # макс параллельных sub-call'ов в одном batch
        budget: 10                  # общесистемный потолок (APCu)
        max_depth: 1                # глубже 1 fan-out не идёт
        connect_timeout: 0.5
        timeout: 10
        self_url: ~                 # null = derive из incoming request
```

**Реальный операционный риск.** Наивная настройка может уложить worker pool.
В бандле **пять слоёв защиты**, но **сначала измеряйте** перед включением в
продакшене:

1. Per-batch cap `max_concurrency`.
2. Общесистемный `budget` через APCu — никогда больше N sub-call'ов в полёте
   суммарно.
3. Recursion guard через заголовок `X-Rpc-Fanout-Depth` — sub-call не может
   снова fan-out'ить.
4. Per-sub-call timeout — застрявший sub-call становится одной ошибкой, а
   не застрявшим batch'ом.
5. Рекомендуемый деплой: **отдельный worker pool** для fan-out
   (`self_url: 'http://127.0.0.1/internal/rpc-fanout'`) — изоляция от пула
   клиентского трафика.

Когда fan-out не может работать (нет HttpClient, нет APCu, batch слишком
мал, depth-limit, budget исчерпан) — контроллер прозрачно деградирует на
sequential. Клиент не замечает ничего кроме чуть большей latency.
`BatchDispatchedEvent` несёт label решения (виден в Web Profiler и в OTel
трейсах) — можно мониторить когда fallback срабатывает.

Требует `symfony/http-client` (hard) и `ext-apcu` (soft). Если
`parallel_batch.enabled: true` и `budget_store: apcu` (default), но APCu не
загружен, бандл откатывается на `NullBudgetTracker` и кидает
`E_USER_WARNING` на этапе сборки контейнера — общесистемный budget в этом
режиме **выключен**, и на FPM это рецепт исчерпания pool'а под нагрузкой.
Чтобы заглушить warning, когда вы намеренно не хотите глобальный cap,
выставьте `budget_store: null` явно.

## Notifications

Запрос без `id` — это notification:

```json
{"jsonrpc":"2.0","method":"audit.log","params":{"event":"login"}}
```

Handler выполняется, тело ответа не отправляется, HTTP-ответ `204`. Даже если
handler бросил — error envelope не возвращается (по спеке). Исключение всё
равно логируется и диспатчится через `MethodInvocationFailedEvent`.

Notifications **не** кэшируются, даже если у метода есть `#[Rpc\Cache]` — они
обычно несут side effects, которые надо применять каждый раз.

## Deprecation

```php
#[Rpc\Method('user.legacy_get', deprecated: 'Use user.get instead.')]
```

Эффекты:

1. **Logger.** Каждый вызов пишет `warning` с `method` и `reason`.
2. **HTTP-заголовки.** Ответы несут `Deprecation: true` и
   `X-Rpc-Deprecated: user.legacy_get: Use user.get instead.`
3. **MCP.** Deprecated-методы скрыты из `/mcp/tools` (если не whitelisted) —
   LLM-агенты не должны цепляться за них как за свежие tools.
4. **OpenRPC.** Метод эмитится с `"deprecated": true` и кастомным полем
   `x-deprecation-reason`.

## Типичные паттерны

### Публичный метод (без ролей)

```php
#[Rpc\Method('public.ping')]
final class Ping
{
    public function __invoke(): array { return ['pong' => true]; }
}
```

### Защищённый метод

```php
#[Rpc\Method('user.delete', roles: ['ROLE_ADMIN'])]
final class DeleteUser { /* … */ }
```

См. [Безопасность и роли](./04-security.md).

### Нужен сырой HTTP-запрос

```php
public function __invoke(Request $request, Context $ctx): array
{
    $ip = $request->getClientIp();
    // …
}
```

`Symfony\Component\HttpFoundation\Request` распознаётся как injectable
параметр — бандл вытягивает его из `RequestStack`.

### Нужен JSON-RPC envelope

```php
public function __invoke(RpcRequest $req, Context $ctx): array
{
    // $req->id, $req->method, $req->params, $req->isNotification
}
```

`Knetesin\JsonRpcServerBundle\Request\RpcRequest` тоже injectable.

### Один метод, разные формы

Имена JSON-RPC методов — плоский namespace. Группируйте префиксами:

```php
#[Rpc\Method('user.get')]
#[Rpc\Method('user.update')]
#[Rpc\Method('user.delete')]
```

Версионирование работает так же — см.
[OpenRPC](./09-openrpc.md#стратегии-версионирования).

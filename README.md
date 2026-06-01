# groupbuy/hyperf-memory-cache

基于 Swoole\Table 的 Hyperf 本地一级缓存（L1），通过注解驱动 AOP 实现透明缓存读写，内置单飞防击穿、TTL 抖动防雪崩、空值缓存防穿透、多表隔离。

## 特性

- **注解驱动**：`#[MemoryCache]` 读缓存 + `#[MemoryCacheEvict]` 写失效，零侵入业务代码
- **Swoole\Table 共享内存**：同进程组所有 Worker 共享，无序列化/反序列化开销（仅存储层序列化）
- **多表隔离**：按 channel 配置独立 Swoole\Table 实例，每个表可设不同 `max_value_bytes`，适配不同业务场景
- **单飞（Single-flight）**：同 Worker 内同 key 并发回源只放行一个，其余协程等待结果
- **TTL 抖动**：`ttl + random_int(1, jitter)` 防止缓存雪崩
- **空值缓存**：`cacheNull: true` 防止缓存穿透
- **安全降级**：缓存层任何异常自动降级走原方法，永不向业务抛
- **自动 Table 注册**：通过 `BeforeMainServerStart` 事件自动创建所有配置的 Swoole\Table，无需手动合并配置
- **运行时指标**：hits/misses/命中率/evicts/errors 等，支持 Prometheus 接入

## 安装

```bash
composer require groupbuy/hyperf-memory-cache
```

## 快速开始

### 1. 启用缓存

在 `.env` 中添加：

```env
MEMORY_CACHE_ENABLE=true
```

### 2. 发布配置文件（可选）

```bash
php bin/hyperf.php vendor:publish groupbuy/hyperf-memory-cache
```

这会将默认配置发布到 `config/autoload/memory_cache.php`。

### 3. 使用注解

#### 读缓存

```php
use Groupbuy\HyperfMemoryCache\Annotation\MemoryCache;

class ConfigService
{
    #[MemoryCache(key: 'cfg:{sid}:{column}', ttl: 30)]
    public function getConfig(string $sid, string $column): array
    {
        return Db::table('shop_config')->where(...)->get()->toArray();
    }
}
```

#### 写失效

```php
use Groupbuy\HyperfMemoryCache\Annotation\MemoryCacheEvict;

class ConfigService
{
    #[MemoryCacheEvict(key: 'cfg:{sid}:{column}')]
    public function setConfig(string $sid, string $column, array $cfg): void
    {
        Db::table('shop_config')->where(...)->update($cfg);
    }
}
```

> **重要**：`MemoryCacheEvict` 的 `key` 模板必须与对应 `MemoryCache` 完全一致，否则失效不生效。

## 多表（Channel）

不同业务场景对单值序列化后字节数上限的需求不同——例如商品详情缓存通常比配置缓存大得多。通过 `channel` 参数将缓存路由到不同的 Swoole\Table 实例，每个表独立配置 `max_value_bytes`。

### 配置

```php
// config/autoload/memory_cache.php
return [
    'enabled' => (bool) env('MEMORY_CACHE_ENABLE', false),
    'tables'  => [
        'default' => [
            'table_name'          => 'memory_cache',
            'table_size'          => (int) env('MEMORY_CACHE_TABLE_SIZE', 16384),
            'max_value_bytes'     => 3500,
            'conflict_proportion' => 0.2,
        ],
        'goods' => [
            'table_name'          => 'memory_cache_goods',
            'table_size'          => (int) env('MEMORY_CACHE_GOODS_TABLE_SIZE', 4096),
            'max_value_bytes'     => 6000,
            'conflict_proportion' => 0.2,
        ],
    ],
    // ... 其他全局配置
];
```

- `default` channel **必须存在**，未显式指定 channel 时所有操作走此表
- 每个 channel 的 `table_name` 必须全局唯一
- 各表 `max_value_bytes` 独立生效，超限值仅旁路跳过，不会截断

### 注解指定 channel

```php
#[MemoryCache(key: 'goods:{id}', channel: 'goods', ttl: 30)]
public function getGoodsDetail(string $id): array { ... }

#[MemoryCacheEvict(key: 'goods:{id}', channel: 'goods')]
public function updateGoodsDetail(string $id, array $data): void { ... }
```

### 编程式指定 channel

```php
$value = $this->manager->remember(
    'goods:123',
    fn () => $this->repository->getGoodsDetail('123'),
    ttl: 30,
    channel: 'goods',
);
```

## 注解参数

### `#[MemoryCache]`

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `key` | `?string` | `null` | Key 模板，使用 `{argName}` 占位符从方法参数按名替换；`null` 时自动生成 `类名::方法名:md5(参数)` |
| `ttl` | `?int` | `null` | TTL（秒）；`null` 使用配置 `default_ttl` |
| `cacheNull` | `bool` | `false` | 是否缓存 `null`/`[]`/`''`/`false` 等空值（防穿透时打开） |
| `singleFlight` | `bool` | `true` | 是否启用单飞防击穿；与全局 `singleflight.enabled` 取 AND |
| `jitter` | `bool` | `true` | 是否对 TTL 加随机抖动防雪崩 |
| `channel` | `string` | `'default'` | 缓存表 channel，对应 `tables` 配置中的 key |

### `#[MemoryCacheEvict]`

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `key` | `string` | 必填 | 要失效的 key 模板，必须与读方法 `#[MemoryCache(key: ...)]` 一致 |
| `alwaysEvict` | `bool` | `false` | 即便方法抛异常也强制清缓存；默认 `false`（事务性写优先） |
| `channel` | `string` | `'default'` | 缓存表 channel，必须与读方法 `#[MemoryCache(channel: ...)]` 一致 |

## Key 模板规则

- 使用 `{argName}` 占位符，从方法参数名按名替换（仅标量参数生效）
- `bool` → `1`/`0`，`null` → `_null_`
- 模板中不允许遗留未替换的占位符，否则抛 `LogicException`
- Key 长度超过 `max_key_length`（默认 60）自动使用 `h:` + md5 缩短
- 不提供 key 时使用默认规则：`类名::方法名:md5(serialize(标量参数))`

```php
#[MemoryCache(key: 'shop:{sid}:cfg:{column}', ttl: 60)]
public function getShopConfig(string $sid, string $column): array { ... }
// 调用 getShopConfig('S001', 'theme') → key = "shop:S001:cfg:theme"
```

## 配置项

配置文件：`config/autoload/memory_cache.php`

### 全局配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `enabled` | `false` | 总开关（`.env: MEMORY_CACHE_ENABLE`）；实时读取，支持一键回滚 |
| `max_key_length` | `60` | Key 超过此长度走 md5 |
| `default_ttl` | `60` | 注解未声明 TTL 时的默认值（秒） |
| `ttl_jitter` | `5` | TTL 抖动上限（秒） |
| `singleflight.enabled` | `true` | 单飞防击穿开关 |
| `singleflight.wait_timeout` | `3.0` | 等待 leader 的协程超时（秒） |
| `serializer` | `auto` | 序列化器：`auto`/`php`/`igbinary`（`.env: MEMORY_CACHE_SERIALIZER`） |
| `eviction_policy` | `lru` | 淘汰策略（`.env: MEMORY_CACHE_EVICTION_POLICY`）；见下方说明 |
| `eviction_batch_size` | `8` | 淘汰时一次删除的行数 |
| `log_value` | `false` | 是否在 debug 日志中输出 value（默认关闭防敏感数据泄露） |

### 多表配置（`tables`）

每个 channel 独立定义一个 Swoole\Table 实例：

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `table_name` | `memory_cache` | Swoole\Table 名称，必须全局唯一 |
| `table_size` | `16384` | Table 行数 |
| `max_value_bytes` | `3500` | 单值序列化后字节数上限；超过不缓存 |
| `conflict_proportion` | `0.2` | Table 哈希冲突比例 |
| `eviction_policy` | 全局值 | 表级淘汰策略，不设置则使用全局 `eviction_policy` |
| `eviction_batch_size` | 全局值 | 表级淘汰批量大小，不设置则使用全局 `eviction_batch_size` |

### 淘汰策略

| 策略 | `eviction_policy` 值 | get 时写操作 | 淘汰排序依据 | 适用场景 |
|------|----------------------|-------------|-------------|---------|
| **概率 LRU** | `lru` | 约 10% 概率只更新 `last_access_at` 列（4 字节） | `last_access_at` | 通用场景，近似 LRU，写开销低 |
| **惰性淘汰** | `lru_lazy` | 无（纯读） | `created_at`（FIFO 语义） | 读密集、表容量充裕、可接受 FIFO |

- `lru`：每次 get 命中时，约 10 次命中才写 1 次 `last_access_at`（只写 4 字节 int 列，不重写整行），兼顾 LRU 精度与读性能
- `lru_lazy`：get 完全不写共享内存，读性能最优；淘汰时按写入时间排序（先入先出），适合缓存写入后访问频率均匀的场景

### 容量估算

```
单表内存占用 ≈ table_size × (max_value_bytes + 16) × (1 + conflict_proportion)
default 表   ≈ 16384 × 3616 × 1.2 ≈ 68 MiB
goods 表     ≈  4096 × 6016 × 1.2 ≈ 28 MiB
```

调整 `table_size` 和 `max_value_bytes` 时注意：
- `max_value_bytes` 必须 ≤ Table value 列实际 size
- 单值超长会被旁路跳过（不写入），不会截断

## 适用场景

### ✅ 适用

高频读 + 低频写 + 可容忍秒级跨机不一致：

- 店铺/加盟店/商家配置
- 字典/静态资源描述
- 商品基础信息（不含价格/库存/上下架状态）

### ❌ 严禁（事故域）

- 库存/余额/计数器/限购
- 订单/支付/优惠券剩余
- 签到/抽奖/福袋/拼团/秒杀状态
- 用户登录态/风控阈值/权限

## 显式调用（不走 AOP）

`MemoryCacheManager` 可在不走 AOP 的场景中直接使用：

```php
use Groupbuy\HyperfMemoryCache\Cache\Memory\MemoryCacheManager;

// 默认 channel
$value = $this->manager->remember(
    'shop:S001:cfg:theme',
    fn () => $this->repository->getConfig('S001', 'theme'),
    ttl: 30,
    cacheNull: true,
    singleFlight: true,
    jitter: true,
);

// 指定 channel
$value = $this->manager->remember(
    'goods:123',
    fn () => $this->repository->getGoodsDetail('123'),
    ttl: 30,
    channel: 'goods',
);
```

### MemoryCacheManager 方法签名

| 方法 | 说明 |
|------|------|
| `get(string $key, string $channel = 'default'): array` | 读取缓存，返回 `['hit' => bool, 'value' => mixed]` |
| `set(string $key, mixed $value, int $ttl, bool $jitter = true, string $channel = 'default'): void` | 写入缓存 |
| `delete(string $key, string $channel = 'default'): void` | 删除缓存 |
| `evict(string $key, string $channel = 'default'): void` | 驱逐缓存（计入 evicts 指标） |
| `remember(string $key, Closure $callback, ?int $ttl = null, bool $cacheNull = false, bool $singleFlight = true, bool $jitter = true, string $channel = 'default'): mixed` | 缓存回源 |
| `clear(string $channel = 'default'): int` | 清空指定 channel 的所有缓存 |
| `clearAll(): int` | 清空所有 channel 的缓存 |
| `snapshot(): array` | 获取运行时指标快照 |

## CLI 命令

```bash
php bin/hyperf.php memory-cache:stats
```

查看当前 L1 缓存配置与指标模板，包含所有 channel 的表状态。

> **注意**：CLI 与 Server 是不同进程，CLI 看不到 Server 的 Swoole\Table 运行时数据。真正的运行时指标请走 Prometheus 接入。

## 架构说明

```
┌──────────────────────────────────────────────────────┐
│  业务方法 #[MemoryCache] / #[MemoryCacheEvict]        │
└──────────────┬───────────────────────────────────────┘
               │ AOP 拦截
               ▼
┌──────────────────────────────────────────────────────┐
│  MemoryCacheAspect                                    │
│  ├─ #[MemoryCache]      → handleRead → remember()    │
│  └─ #[MemoryCacheEvict] → handleEvict → evict()      │
│  异常降级：任何异常 → warning + 走原方法              │
└──────────────┬───────────────────────────────────────┘
               ▼
┌──────────────────────────────────────────────────────┐
│  MemoryCacheManager                                   │
│  ├─ LocalCacheTablePool   按 channel 获取 Table 实例 │
│  ├─ CacheValueSerializer  igbinary / php serialize    │
│  ├─ SingleFlightManager   防击穿                     │
│  └─ MemoryCacheMetrics    命中率等指标                │
└──────────────┬───────────────────────────────────────┘
               ▼
┌──────────────────────────────────────────────────────┐
│  LocalCacheTablePool                                  │
│  ├─ channel 'default' → LocalCacheTable (3500B)      │
│  ├─ channel 'goods'   → LocalCacheTable (6000B)      │
│  └─ ...                                              │
└──────────────────────────────────────────────────────┘
```

### Swoole\Table 自动注册

包通过 `MemoryCacheTableInitializer` 监听 `BeforeMainServerStart` 事件，在 Server 启动前遍历 `tables` 配置，自动创建所有 Swoole\Table 实例。无需手动在 `swoole_table.php` 中添加表定义。

### 缓存失效语义

- `#[MemoryCacheEvict]` 在方法**成功执行后**清缓存
- 方法抛异常时**不清**（除非 `alwaysEvict=true`），避免失败写引发数据不一致
- 同一方法同时有 `MemoryCache` + `MemoryCacheEvict` 注解时，以 Evict 为准（写优先）

## 升级指南

### v1.1.x → v1.2.0

v1.2.0 新增多表（channel）支持，**完全向后兼容**：

- 未显式指定 `channel` 时默认走 `'default'`，行为与 v1.1.x 一致
- 旧的单表配置（`table_name`/`table_size`/`max_value_bytes` 在顶层）仍可使用，初始化器会自动兼容
- 如需使用多表，将顶层表配置迁移至 `tables.default`，并按需添加其他 channel

## 测试

```bash
composer install
composer test
```

## License

MIT

# groupbuy/hyperf-memory-cache

基于 Swoole\Table 的 Hyperf 本地一级缓存（L1），通过注解驱动 AOP 实现透明缓存读写，内置单飞防击穿、TTL 抖动防雪崩、空值缓存防穿透。

## 特性

- **注解驱动**：`#[MemoryCache]` 读缓存 + `#[MemoryCacheEvict]` 写失效，零侵入业务代码
- **Swoole\Table 共享内存**：同进程组所有 Worker 共享，无序列化/反序列化开销（仅存储层序列化）
- **单飞（Single-flight）**：同 Worker 内同 key 并发回源只放行一个，其余协程等待结果
- **TTL 抖动**：`ttl + random_int(1, jitter)` 防止缓存雪崩
- **空值缓存**：`cacheNull: true` 防止缓存穿透
- **安全降级**：缓存层任何异常自动降级走原方法，永不向业务抛
- **自动 Table 注册**：通过 `BeforeMainServerStart` 事件自动创建 Swoole\Table，无需手动合并配置
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

## 注解参数

### `#[MemoryCache]`

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `key` | `?string` | `null` | Key 模板，使用 `{argName}` 占位符从方法参数按名替换；`null` 时自动生成 `类名::方法名:md5(参数)` |
| `ttl` | `?int` | `null` | TTL（秒）；`null` 使用配置 `default_ttl` |
| `cacheNull` | `bool` | `false` | 是否缓存 `null`/`[]`/`''`/`false` 等空值（防穿透时打开） |
| `singleFlight` | `bool` | `true` | 是否启用单飞防击穿；与全局 `singleflight.enabled` 取 AND |
| `jitter` | `bool` | `true` | 是否对 TTL 加随机抖动防雪崩 |

### `#[MemoryCacheEvict]`

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `key` | `string` | 必填 | 要失效的 key 模板，必须与读方法 `#[MemoryCache(key: ...)]` 一致 |
| `alwaysEvict` | `bool` | `false` | 即便方法抛异常也强制清缓存；默认 `false`（事务性写优先） |

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

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `enabled` | `false` | 总开关（`.env: MEMORY_CACHE_ENABLE`）；实时读取，支持一键回滚 |
| `table_name` | `memory_cache` | Swoole\Table 名称 |
| `table_size` | `16384` | Table 行数（`.env: MEMORY_CACHE_TABLE_SIZE`） |
| `conflict_proportion` | `0.2` | Table 哈希冲突比例 |
| `max_key_length` | `60` | Key 超过此长度走 md5 |
| `max_value_bytes` | `3500` | 单值序列化后字节数上限；超过不缓存 |
| `default_ttl` | `60` | 注解未声明 TTL 时的默认值（秒） |
| `ttl_jitter` | `5` | TTL 抖动上限（秒） |
| `singleflight.enabled` | `true` | 单飞防击穿开关 |
| `singleflight.wait_timeout` | `3.0` | 等待 leader 的协程超时（秒） |
| `serializer` | `auto` | 序列化器：`auto`/`php`/`igbinary`（`.env: MEMORY_CACHE_SERIALIZER`） |
| `log_value` | `false` | 是否在 debug 日志中输出 value（默认关闭防敏感数据泄露） |

### 容量估算

```
内存占用 ≈ table_size × (max_value_bytes + 16) × (1 + conflict_proportion)
默认    ≈ 16384 × 3616 × 1.2 ≈ 68 MiB / Worker
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

$value = $this->manager->remember(
    'shop:S001:cfg:theme',
    fn () => $this->repository->getConfig('S001', 'theme'),
    ttl: 30,
    cacheNull: true,
    singleFlight: true,
    jitter: true,
);
```

## CLI 命令

```bash
php bin/hyperf.php memory-cache:stats
```

查看当前 L1 缓存配置与指标模板。

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
│  ├─ LocalCacheTable       Swoole\Table 读写          │
│  ├─ CacheValueSerializer  igbinary / php serialize    │
│  ├─ SingleFlightManager   防击穿                     │
│  └─ MemoryCacheMetrics    命中率等指标                │
└──────────────────────────────────────────────────────┘
```

### Swoole\Table 自动注册

包通过 `MemoryCacheTableInitializer` 监听 `BeforeMainServerStart` 事件，在 Server 启动前自动创建 Swoole\Table。无需手动在 `swoole_table.php` 中添加表定义。

### 缓存失效语义

- `#[MemoryCacheEvict]` 在方法**成功执行后**清缓存
- 方法抛异常时**不清**（除非 `alwaysEvict=true`），避免失败写引发数据不一致
- 同一方法同时有 `MemoryCache` + `MemoryCacheEvict` 注解时，以 Evict 为准（写优先）

## 测试

```bash
composer install
composer test
```

## License

MIT

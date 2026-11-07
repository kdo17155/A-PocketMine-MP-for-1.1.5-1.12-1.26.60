# A PocketMine-MP for 1.1.5 / 1.12-1.26.60

基于 [PocketMine-MP](https://github.com/pmmp/PocketMine-MP) 的自定义多协议 Minecraft: Bedrock Edition 服务端源码，让不同版本的客户端可以加入同一个服务器。

## 支持的客户端版本（版本加入）

| 版本范围 | 说明 |
| --- | --- |
| **1.1.5** | 老版本 MCPE 客户端加入支持 |
| **1.12 ~ 1.26.60** | 该区间内的各小版本客户端加入支持 |

即：**支持 1.1.5 和 1.12–1.26.60 版本加入**，两档协议由服务端的多协议兼容层统一处理。

本项目在 **1.12–1.26.32** 版本基础上修改而来，并扩展了上述版本加入范围。

## 致谢

- **DFkits** —— 本项目基于 DFkits 的 1.12–1.26.32 版本修改而来，感谢其提供的实现与思路
- **PocketMine-MP** —— 核心服务端框架与基础源码
- **NetherGames-PocketMine-MP** —— 多协议与新版协议实现参考（含 `bedrock-data` 等数据支持）

## 目录结构

| 路径 | 说明 |
| --- | --- |
| `src/` | 核心源码，入口为 `src/PocketMine.php`（无 `src/pocketmine/` 子层级） |
| `vendor/` | 依赖库，包含 `pocketmine/*`、`nethergamesmc/bedrock-data` 等 |
| `resources/` | 默认配置（`pocketmine.yml`）、语言文件、vanilla 资源 |
| `generated/` | 构建期生成的方块 / 物品 / 数据常量类 |
| `_phar_stub.php` | 原始 PHAR 的启动引导代码，供参考 |

## 说明

- 本仓库内容由 `PocketMine-MP-1.1.5-1.26.60.phar` 解包得到，共 3081 个文件，每个文件均已通过 CRC32 校验；原始 `.phar` 属于构建产物，未包含在本仓库中。
- 运行需要 PHP 8.x 及相应扩展；启动引导逻辑可参见 `_phar_stub.php`。
- 代码许可证遵循上游 PocketMine-MP 及各依赖库各自的许可证（见 `vendor/*/LICENSE*`）。

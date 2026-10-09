# 驭舟独立备份与恢复演练

`backup.py` 仅面向 `/www/wwwroot/yuzhou`、`127.0.0.1` 的 `yuzhou` 数据库和 `shared/storage`。它从本项目 `shared/.env` 读取 `yuzhou_app` 凭据，不读取宝塔管理员口令、创意岛配置或其他库。需要服务器 `/usr/bin/python3` 3.8 以上；MySQL 客户端使用宝塔的 `/www/server/mysql/bin/{mysql,mysqldump}`。

## 备份

管理员执行：

```sh
/usr/bin/python3 /www/wwwroot/yuzhou/current/deploy/backup.py backup
```

程序要求全部表为 InnoDB。`mysqldump` 使用单事务、关闭源表锁和输出中的 `DROP TABLE`/`LOCK TABLES`，只导出 `yuzhou`。密码写入执行期间的临时 `--defaults-extra-file`（0600），正常完成或失败均清理；命令参数和输出不含密码，客户端错误正文不会进入日志。

备份目录为 `/www/wwwroot/yuzhou/backups`（root 0700）。与 installer 管理的 `shared/backups` 分开；目录位于 Web 根目录之外。每个完成集合包括：

| 文件 | 内容 |
|---|---|
| `database.sql` | 本项目表结构及数据；0600 |
| `storage.tar` | 私有/公开上传对象原始字节；0600 |
| `complete.json` | 工具标记、源提交/发布、表清单、两个文件SHA256、每个对象大小和SHA256；0600 |

备份先写私有 `.pending-*`，所有数据与回执同步后原子改名为 `yuzhou-backup-<UTC时间>-<随机值>`。失败不发布完成集合。并发执行通过本目录专用 `flock` 拒绝。保留当前新集合和最近六个本工具完成集合；额外文件、其他工具回执、符号链接和不完整目录不会被自动清理。意外终止的 `.pending-*` 需管理员检查后清理；定期检查磁盘余量、退出码和最近一次完整回执。

## 一致性条件

当前 FileStore 先保存随机命名的完整文件，再提交 `uploads` 数据库记录；已提交的对象内容不会修改，正常功能没有物理删除路径。程序先完成数据库事务快照，再复制储存目录；正常新增上传可继续，较晚上传或孤立对象也可能进入备份。复制中检测文件 inode、大小和修改时间变化，拒绝异常名字、目录及符号链接，不静默跳过资料。测试种子已有的 `development-license-<16位十六进制>.png` 也会保留。

备份期间不得运行建表/迁移、TRUNCATE、改名或其他 DDL，也不得人工删除/覆盖 storage 文件。当前项目没有数据库视图、触发器、存储过程或事件；这些对象未纳入本工具备份，增加它们时须先修改并重新验证备份方案。上述条件下的上传顺序支持快照中的文件引用恢复；任意时点同时改库和删除文件仍需要维护窗口或专用快照方案。[MySQL 5.7 单事务与权限说明](https://dev.mysql.com/doc/refman/5.7/en/mysqldump.html)。

## 恢复到专用演练目标

工具拒绝恢复到活跃的 `yuzhou`，不会修改线上 `.env`、current 链接或上传目录。请由管理员先准备一个**不存在业务数据的独立库**，例如 `yuzhou_restore_20261009`，字符集与线上相同（utf8mb4/utf8mb4_unicode_ci），以及仅拥有该库权限的专用 `yuzhou_restore_*` 本地账号。账号长度不超过32字符。无需把管理员口令交给恢复工具。

权限可采用该库的 `SELECT,INSERT,CREATE,REFERENCES`；不要赋全局权限或 `WITH GRANT OPTION`。MySQL 5.7 的数据库级授权会把 `_` 当作通配符，必须在 `GRANT` 的库名中转义，例如 ``ON `yuzhou\_restore\_20261009`.*``。程序读取 `SHOW GRANTS` 并拒绝未转义的库名或其他库/全局权限。[MySQL 5.7 授权匹配说明](https://dev.mysql.com/doc/mysql-security-excerpt/5.7/en/request-access.html)。

把新账号的信息保存到本项目 root 私有目录中的 JSON 文件（root 0600），只含以下四个字段；port 须与独立 `shared/.env` 中 `DB_PORT` 相同：

```json
{"host":"127.0.0.1","port":"3306","user":"yuzhou_restore_test","password":"单独生成的临时口令"}
```

选定已完成的绝对备份路径、空数据库和**尚不存在**的独立储存目标后执行：

```sh
/usr/bin/python3 /www/wwwroot/yuzhou/current/deploy/backup.py restore \
  --backup /www/wwwroot/yuzhou/backups/yuzhou-backup-20261009T000000Z-12345678 \
  --database yuzhou_restore_20261009 \
  --credentials /www/wwwroot/yuzhou/restore-credentials.json \
  --storage-target /www/wwwroot/yuzhou/restore-drills/20261009
```

示例时间、库名和凭据路径须替换为本次实际目标。程序先校验完整备份哈希、账号作用域和空库，再验证每个 tar 成员的名字/类型/大小/哈希后写入 root 0700 演练目录。导入不使用 `--force`；完成后查询真实表清单，与备份清单一致才写 `restore-complete.json`，其中 `activated=false`。任何恢复失败均保留演练库和已复制文件供排查，不输出 DROP 脚本；失败目录不能直接复用，下一次需新的空库/储存目标。

演练验收还须由管理员另行核对，并把结果保存在私有部署记录中：

1. 源快照/恢复库的表清单一致；外键定义、商家 `credit_code` 主键、关键账本行与订单状态正确。
2. 逐行读取恢复库 `uploads.storage_name/size_bytes/sha256`，确认恢复目录中的对应文件存在、大小和SHA256相同。**该数据库引用核对不由工具自动执行**；工具只验证备份 inventory 与恢复对象一致。
3. 用隔离的临时应用配置和测试资料验证需要的读操作；工具完成回执不代替应用恢复验收，也不会自动切换线上。

## 单独安装定时任务

先完成一次人工备份和独立恢复演练，再安装自有任务。请使用包含本工具的已审核发布：

```sh
install -d -m 0700 -o root -g root /www/wwwroot/yuzhou/backups
install -m 0644 /www/wwwroot/yuzhou/current/deploy/yuzhou-backup.service /etc/systemd/system/yuzhou-backup.service
install -m 0644 /www/wwwroot/yuzhou/current/deploy/yuzhou-backup.timer /etc/systemd/system/yuzhou-backup.timer
systemctl daemon-reload
systemctl start yuzhou-backup.service
systemctl enable --now yuzhou-backup.timer
systemctl show yuzhou-backup.service -p Result -p ExecMainStatus
systemctl list-timers yuzhou-backup.timer
```

timer 使用**服务器本地时间**每天03:30，最多延迟5分钟；安装时确认服务器时区，不修改其他站点任务。服务仅能写独立 backups，正常源库用户仍只有原有CRUD权限；无需扩大其全局授权。部署前后需暂停本 timer 并等待正在运行的 backup 服务完成，然后运行数据库迁移，再启用 timer。

## 已验证与边界

本地 `python3 -B tests/deploy/test_backup.py` 使用可执行假 MySQL 客户端和临时目录，验证密码/权限、快照参数、失败回执、保留范围、种子执照文件、空库和隔离授权、路径逃逸、哈希、导入失败及恢复表清单。实际阿里云备份、恢复演练和 timer 激活应另记实测结果；本文件不预先声称已经完成。

本机备份会随服务器磁盘损毁丢失。异地加密复制、灾备切换和长期定时任务可靠性尚未验收；当前工具不备份环境密钥、证书或签名，这些应由管理员在独立安全位置保管。

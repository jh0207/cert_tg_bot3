# Telegram SSL 证书机器人

## 异步任务（Cron）

通过 Cron 定时执行证书处理任务，避免阻塞 Webhook。

```
* * * * * php /www/wwwroot/tg-cert-bot/think cert:process
```

命令内置了单实例锁：当上一轮任务尚未结束时，新的 `cert:process` 会自动跳过，避免并发重复处理同一批订单。

默认导出目录位于站点 `public/ssl/`，可通过 `CERT_EXPORT_PATH` 覆盖。

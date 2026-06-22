# WeChatDraft 插件

WeChatDraft 是一个用于 Typecho 博客系统的插件，它可以在发布文章的同时将内容同步到微信公众号的草稿中。这个插件支持带图同步，让你可以更方便地在微信上发布文章。只需在微信公众平台或订阅号助手 APP 根据自己的需要稍作调整，即可完成发布，填补了 Typecho 插件在个人公众号领域的空白。

## 功能特点

- 将文章内容同步到微信公众号的草稿中。
- 支持同步带图，将文章中的图片自动上传至微信服务器。
- 与订阅号助手 APP 配合使用，轻松完成文章发布。

## 安装方法

1. 将插件文件夹 `WeChatDraft` 复制到 Typecho 的插件目录 `/usr/plugins/` 下。
2. 登录 Typecho 后台，进入“控制台” -> “插件管理”。
3. 找到 WeChatDraft 插件，并点击“启用”。

## 配置说明

在启用插件后，你需要进行一些简单的配置步骤才能使用 WeChatDraft 插件：

1. 获取微信公众号的 AppID 和 AppSecret。你需要在微信公众平台上创建一个公众号，并获取到相应的 AppID 和 AppSecret。
2. 在 Typecho 后台的“设置” -> “插件” -> “WeChatDraft”页面中，填入获取到的 AppID 和 AppSecret。
3. 保存设置，配置完成。

## 使用方法

使用 WeChatDraft 插件非常简单：

1. 在 Typecho 编辑器中编写你的文章，并设置好标题、内容、标签等相关信息。
2. **决定这篇文章是否要同步到公众号**（见下方"同步策略"）。
3. 点击「发布」按钮：被标记同步的文章会自动进入微信公众号草稿箱。
4. 打开微信公众平台或订阅号助手 APP，在草稿箱中找到草稿，稍作调整并发布即可。

注意：确保你已经正确配置了微信公众号的相关信息。

## 同步策略

插件提供「默认同步策略」开关，决定**手动发文**默认是发还是不发：

| 默认策略 | 默认行为 | 想要相反行为时 |
|---|---|---|
| **不勾选**（默认，opt-in） | 所有手动发文**不**同步 | 文章里加正向标记 → 同步 |
| **勾选**（opt-out） | 所有手动发文**都**同步 | 文章里加反向标记 → 跳过 |

### 三种标记方式（任一命中即触发）

假设「同步关键词」是默认的 `wechat`：

1. **正文注释**（最方便，写在 Markdown 任何位置）
   - 正向：`<!--wechat-->`
   - 反向：`<!--no-wechat-->`
2. **文章标签**（在编辑页右侧标签框里加）
   - 正向：标签名为 `wechat`
   - 反向：标签名为 `no-wechat`
3. **文章分类**（按分类的名字或 slug 匹配，都行）
   - 正向：分类名/slug 为 `wechat`
   - 反向：分类名/slug 为 `no-wechat`

「同步关键词」可以在配置页改。修改后，文章里已有的旧标签/注释也得相应改名。

### 其他插件通过 API 调用

外部插件（如 AiDailyPost）通过 `WeChatDraft_Plugin::syncDraft()` 主动调用时，**不经过**上述判断逻辑—— API 调用者全权决定是否要同步。

## 给其他插件提供的公开 API

如果你写的 Typecho 插件需要把文章同步到微信公众号草稿箱，可以直接调用本插件暴露的静态方法：

```php
if (class_exists('WeChatDraft_Plugin')) {
    $result = WeChatDraft_Plugin::syncDraft(
        $title,        // 文章标题（string）
        $contentHtml,  // 文章正文 HTML（string，非 Markdown）
        $sourceUrl,    // 原文链接，用于 content_source_url（string）
        $authorOverride = null  // 可选：覆盖默认作者名，传 null 走插件配置或用户昵称
    );
    // $result = ['success' => bool, 'message' => string, 'media_id' => string|null]
}
```

特性：
- 异常一律转成返回值，**不会向调用方抛出异常**——草稿同步失败不应该让上游业务跟着挂。
- 自动校验 AppID / Secret 配置；缺失时返回 `success=false` 并附带提示。
- 自动处理 `<img>` 标签：远程 URL 先下载到本地 tmp 再上传到微信素材库（兼容 CDN 链接），然后替换 src 为微信 URL。
- 复用本插件的 `access_token` 缓存与 `thumb_media_id` 缓存。

调用方需要自己负责：
- 把 Markdown / Editor.md / 富文本等格式转成 HTML（微信草稿 API 只认 HTML）。
- 如果不希望微信文章页出现重复大标题，在传入前剥掉正文顶部的 `<h1>`（公众号标题字段是独立传的）。
- 错误处理：根据 `$result['success']` 决定是否要重试 / 写日志。

**个人订阅号限制**：微信平台不向个人订阅号开放群发 API，文章只能进入草稿箱；最后一步「发布给粉丝」需在订阅号助手 APP 手动点击。这是平台规则，任何插件都绕不过去。

## 支持

如果本插件帮到了你，不妨给点赞赏鼓励一下作者

<img width="300" height="300" alt="支付宝" src="https://raw.githubusercontent.com/qiuzhangsaer/imageWarehouse/main/alipay.jpg"><img width="300" height="300" alt="微信" src="https://raw.githubusercontent.com/qiuzhangsaer/imageWarehouse/main/wechat.jpg">


## 帮助

如果在安装、配置或使用 WeChatDraft 插件过程中遇到任何问题，请查阅以下资源获取帮助：

- Typecho 官方论坛：[https://forum.typecho.org/](https://forum.typecho.org/)
- 蓄客博客插件说明：[https://www.xvkes.cn/archives/290/](https://www.xvkes.cn/archives/290/)
- 项目仓库：[https://github.com/qiuzhangsaer/WeChatDraft](https://github.com/qiuzhangsaer/WeChatDraft)
- 提交 Issue：[https://github.com/qiuzhangsaer/WeChatDraft/issues](https://github.com/qiuzhangsaer/WeChatDraft/issues)

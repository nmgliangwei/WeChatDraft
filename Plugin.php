<?php

/**
 * 发布文章同步提交微信公众号草稿
 *
 * @package WeChatDraft
 * @author LiangWei
 * @version 1.0.4
 * @link https://www.liangwei.cc
 */
class WeChatDraft_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    /* 激活插件方法 */
    public static function activate(){
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('WeChatDraft_Plugin', 'render');
        Helper::addRoute('reset_mediaid', '/reset_mediaid', 'WeChatDraft_Action', 'resetMediaId');
    }

    /* 禁用插件方法 */
    public static function deactivate(){
        Helper::removeRoute('reset_mediaid');
        $dirPath = dirname(__FILE__) . '/cache';
        $files = glob($dirPath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file); // 删除文件
            }
        }
    }

    /* 插件配置方法 */
    public static function config(Typecho_Widget_Helper_Form $form){
        // 添加App ID字段
        $appid = new Typecho_Widget_Helper_Form_Element_Text('appid', NULL, '', _t('APPID'), _t('请填写微信公众号的APPID'));
        $form->addInput($appid);

        // 添加Secret字段
        $secret = new Typecho_Widget_Helper_Form_Element_Text('secret', NULL, '', _t('Secret'), _t('请填写微信公众号的Secret'));
        $form->addInput($secret);

        // 添加Author字段
        $author = new Typecho_Widget_Helper_Form_Element_Text('author', NULL, '', _t('作者'), _t('请填写文章作者，默认使用个人资料中的昵称，长度不得超过8个汉字</br> 如要更改封面图片，请在公众平台上传图片后点击<a href="' . Helper::options()->index . '/reset_mediaid">重置封面缓存</a>，后续使用时会自动获取新的图片'));
        $form->addInput($author);

        // 同步策略：默认是 opt-in（"按需触发"），勾选后改为 opt-out（"按需排除"）
        $autoSyncDefault = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'autoSyncDefault',
            ['enable' => _t('默认对所有手动发文自动同步到公众号草稿')],
            [],
            _t('默认同步策略'),
            _t('不勾选（默认）：手动发文不会自动同步，需在文章正文加 <code>&lt;!--wechat--&gt;</code> 注释、'
              . '或文章带「同步关键词」对应的标签/分类，才会触发同步。<br>'
              . '勾选后：所有手动发文都会自动同步，除非用上述任一标记主动排除（注释改为 <code>&lt;!--no-wechat--&gt;</code>，'
              . '标签/分类名加 <code>no-</code> 前缀，如 <code>no-wechat</code>）。<br>'
              . '注意：其他插件通过 API 调用 <code>syncDraft()</code> 不受此开关影响。')
        );
        $form->addInput($autoSyncDefault);

        // 同步关键词（注释 / 标签 / 分类名都用它）
        $syncTriggerKeyword = new Typecho_Widget_Helper_Form_Element_Text(
            'syncTriggerKeyword', NULL, 'wechat',
            _t('同步关键词'),
            _t('用于注释标记和标签/分类名匹配。默认 <code>wechat</code>，即：<br>'
              . '• 正文含 <code>&lt;!--wechat--&gt;</code>（opt-in 模式）/ <code>&lt;!--no-wechat--&gt;</code>（opt-out 模式）<br>'
              . '• 文章有名为 <code>wechat</code> / <code>no-wechat</code> 的标签或分类<br>'
              . '换成 <code>mp</code> 之类的也行，但需重启所有相关文章中的标签/注释一并改名。')
        );
        $form->addInput($syncTriggerKeyword);

        // 默认封面图
        $defaultThumbUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'defaultThumbUrl', NULL, '',
            _t('默认封面图 URL'),
            _t('填一张公网可访问的图片地址，插件自动上传到微信永久素材库作为图文封面。<br>'
              . '建议尺寸 900×383 像素（2.35:1），JPG/PNG 格式。<br>'
              . '与「封面图优先策略」配合使用：<br>'
              . '• <b>文章首图优先</b>（默认）：用文章正文里的第一张图，没有图时才用此默认封面；都没有则取素材库第一张。<br>'
              . '• <b>默认封面优先</b>：每篇文章都用此默认封面；未填写本项则降级到文章首图；再没有则取素材库第一张。<br>'
              . '修改 URL 后插件会自动重新上传（老的素材库图片不会被删除）。')
        );
        $form->addInput($defaultThumbUrl);

        // 封面图优先策略
        $coverStrategy = new Typecho_Widget_Helper_Form_Element_Radio(
            'coverStrategy',
            [
                'article_first' => _t('文章首图优先（推荐）：文章里有图就用文章首图，没有才用默认封面'),
                'default_first' => _t('默认封面优先：所有文章统一使用上面配置的默认封面；未配置则降级到文章首图'),
            ],
            'article_first',
            _t('封面图优先策略'),
            _t('控制图文封面 thumb_media_id 的来源顺序。<br>'
              . '两个模式都会在最终找不到图时回退到「微信素材库的第一张图」作为兜底。')
        );
        $form->addInput($coverStrategy);

        // 下载图片时携带的 Referer（用于绕过 CDN 防盗链）
        $downloadReferer = new Typecho_Widget_Helper_Form_Element_Text(
            'downloadReferer', NULL, '',
            _t('下载图片时的 Referer（防盗链）'),
            _t('如果你的图片走 CDN 且开启了防盗链，PHP curl 下载图片会被拒绝。<br>'
              . '在此填入一个被 CDN 白名单允许的来源 URL，如 <code>https://www.liangwei.cc/</code>。<br>'
              . '留空则自动使用站点首页 URL。<br>'
              . '影响范围：正文图片上传 + 封面图下载（含「文章首图作为封面」、「默认封面图 URL」两条路径）。')
        );
        $form->addInput($downloadReferer);

        // User-Agent
        $downloadUserAgent = new Typecho_Widget_Helper_Form_Element_Text(
            'downloadUserAgent', NULL, '',
            _t('下载图片时的 User-Agent'),
            _t('某些 CDN / 防盗链会按 UA 判断是不是浏览器。留空走默认 <code>WeChatDraft/1.0</code>。<br>'
              . '常见浏览器 UA 示例（直接复制粘贴）：<br>'
              . '<code>Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36</code>')
        );
        $form->addInput($downloadUserAgent);

        // 额外请求头
        $downloadExtraHeaders = new Typecho_Widget_Helper_Form_Element_Textarea(
            'downloadExtraHeaders', NULL, '',
            _t('下载图片时的额外请求头（高级）'),
            _t('每行一个 HTTP 头，<code>Header-Name: value</code> 格式。<br>'
              . '示例：<br>'
              . '<code>Cookie: token=abc</code><br>'
              . '<code>X-Forwarded-For: 1.2.3.4</code><br>'
              . '一般用不到，留空即可。')
        );
        $form->addInput($downloadExtraHeaders);

        // 超时时间
        $downloadTimeout = new Typecho_Widget_Helper_Form_Element_Text(
            'downloadTimeout', NULL, '30',
            _t('下载图片超时时间（秒）'),
            _t('单张图片下载超过此秒数将放弃。默认 30 秒。大图或网络慢可改 60。')
        );
        $form->addInput($downloadTimeout);

        // 代理
        $downloadProxy = new Typecho_Widget_Helper_Form_Element_Text(
            'downloadProxy', NULL, '',
            _t('HTTP 代理（可选）'),
            _t('如果服务器到 CDN 的网络受限，可以配代理。格式：<code>http://host:port</code> 或 <code>socks5://host:port</code>。<br>'
              . '留空则直连。仅影响图片下载，不影响微信 API 调用。')
        );
        $form->addInput($downloadProxy);

        // ====== 排版美化 ======
        // 微信公众号编辑器只认 inline style，不识别 <style>/外链 CSS。
        // 启用后插件会在提交草稿前用 DOMDocument 给 h1-h6 / p / ul / ol / table /
        // blockquote / inline code / pre 等标签注入 inline style，并在最外层
        // 包一层「nice」section 统一字体行高。已有 style 属性的标签会保留原值，
        // 只补未设置的属性，不会覆盖博客主题已经设定的样式。
        $beautifyHtml = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'beautifyHtml',
            ['enable' => _t('启用排版美化（推荐）')],
            [],
            _t('排版美化'),
            _t('提交草稿前给正文 HTML 注入 inline style，让微信公众号里的排版风格更接近 markdown 编辑器。<br>'
              . '处理范围：整体字体/行高、h1–h6 标题、段落、有序/无序列表、表格、引用块、行内 code、代码块。<br>'
              . '已有 <code>style</code> 属性的标签保留原值（只补未设置的属性），不影响博客原有样式。<br>'
              . '默认关闭。开启后如果发现某种排版需要调整，欢迎反馈。')
        );
        $form->addInput($beautifyHtml);

        // 主题色：用于 h1/h2 描边、blockquote 左侧竖线、inline code 文字、strong 加粗等
        $beautifyThemeColor = new Typecho_Widget_Helper_Form_Element_Text(
            'beautifyThemeColor', NULL, 'hsl(216, 100%, 68%)',
            _t('美化主题色'),
            _t('排版美化使用的主色，会出现在标题描边、引用块左侧竖线、行内 code、加粗等位置。<br>'
              . '默认 <code>hsl(216, 100%, 68%)</code>（蓝色）。可填任何合法 CSS 颜色，如 <code>#1e88e5</code>、<code>rgb(255, 99, 71)</code>。<br>'
              . '仅在「排版美化」启用时生效。')
        );
        $form->addInput($beautifyThemeColor);
    }

    /* 个人用户的配置方法 */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 日志文件路径
     */
    public static function logFile()
    {
        return dirname(__FILE__) . '/wechat-draft.log';
    }

    /**
     * 写日志（每条都带时间戳；自动 rotate 防止文件爆炸）
     *
     * 因为整个流程都在 finishPublish 钩子里跑，向 stdout 输出会污染响应；
     * 异常又会被钩子吞掉。所以必须落地到文件，调试时直接 tail。
     *
     * @param string $message
     * @param string $level INFO / WARN / ERROR
     */
    public static function log($message, $level = 'INFO')
    {
        $file = self::logFile();
        // 简单 rotate：超过 1MB 就 truncate（保留最新日志，丢弃历史）
        if (file_exists($file) && filesize($file) > 1024 * 1024) {
            @file_put_contents($file, '');
        }
        $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$message}\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /* 获取微信access_token的方法 */
    public static function getAccessToken()
    {
        // 检查缓存中是否存在access_token
        $file = dirname(__FILE__) . '/cache/accessToken';
        $accessToken = file_exists($file) ? unserialize(file_get_contents($file)) : '';
        if (empty($accessToken) || self::isAccessTokenExpired($accessToken)) {
            // 如果缓存中不存在或已过期，重新请求获取access_token
            $newAccessToken = self::requestAccessToken();

            // 将新的access_token存储到缓存中
            file_put_contents($file, serialize($newAccessToken));

            return $newAccessToken->access_token;
        }

        return $accessToken->access_token;
    }

    /* 判断access_token是否过期的方法 */
    public static function isAccessTokenExpired($accessToken)
    {
        $time = time();
        if ($time > ($accessToken->expires_time)) {
            return true;
        }
        return false; // 假设access_token未过期
    }

    /* 请求获取新的微信access_token的方法 */
    public static function requestAccessToken()
    {
        $appid = Helper::options()->plugin('WeChatDraft')->appid;
        $secret = Helper::options()->plugin('WeChatDraft')->secret;
        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.$appid.'&secret='.$secret;

        $newAccessToken = self::curl($url);
        $newAccessToken->expires_time = time()+$newAccessToken->expires_in;

        return $newAccessToken;
    }

    /* 获取 thumb_media_id 用作图文消息的封面
     *
     * 优先级：
     *   1. 配置了「默认封面图 URL」→ 自动上传到永久素材库；URL 不变则用缓存
     *   2. 没配 URL → 从已有图片素材中取第一张（兼容老逻辑）
     *
     * 缓存策略：
     *   /cache/mediaId       → 缓存的 media_id（字符串）
     *   /cache/mediaIdSource → 上次缓存对应的 URL（用于检测 URL 变更）
     */
    public static function getMediaId(){
        $cacheDir   = dirname(__FILE__) . '/cache';
        $cacheFile  = $cacheDir . '/mediaId';
        $sourceFile = $cacheDir . '/mediaIdSource';

        $setting = Helper::options()->plugin('WeChatDraft');
        $defaultThumbUrl = isset($setting->defaultThumbUrl) ? trim($setting->defaultThumbUrl) : '';

        // 路径 1: 配了默认封面图 URL → 自动上传到永久素材库
        if ($defaultThumbUrl !== '') {
            $cachedSource = file_exists($sourceFile) ? trim(file_get_contents($sourceFile)) : '';
            $cachedMediaId = file_exists($cacheFile) ? trim(file_get_contents($cacheFile)) : '';

            // URL 没变 && 缓存有效 → 直接用缓存
            if ($cachedMediaId !== '' && $cachedSource === $defaultThumbUrl) {
                self::log("使用缓存的封面 media_id（URL 未变）");
                return $cachedMediaId;
            }

            // URL 变了 / 首次配置 → 重新上传
            self::log("默认封面 URL " . ($cachedSource === '' ? '首次配置' : '已变更') . "，开始上传到永久素材库");
            $mediaId = self::uploadPermanentThumb($defaultThumbUrl);
            if ($mediaId !== false) {
                @file_put_contents($cacheFile, $mediaId);
                @file_put_contents($sourceFile, $defaultThumbUrl);
                self::log("封面图上传成功，新 media_id: {$mediaId}");
                return $mediaId;
            }
            self::log("默认封面图上传失败，回退到「素材库取第一张」逻辑", 'WARN');
            // 失败则继续走老逻辑
        }

        // 路径 2: 没配 URL（或上传失败回退）→ 从素材库取第一张
        $mediaId = file_exists($cacheFile) ? trim(file_get_contents($cacheFile)) : '';
        if (!empty($mediaId)) {
            // 注意：如果你后台启用了默认封面图但又删了，可能要手动清缓存
            return $mediaId;
        }

        $accessToken = self::getAccessToken();
        $url = 'https://api.weixin.qq.com/cgi-bin/material/batchget_material?access_token=' . $accessToken;
        $array = [
            'type'   => 'image',
            'offset' => 0,
            'count'  => 20,
        ];
        $resp = self::curl($url, json_encode($array), true);
        $mediaList = isset($resp->item) ? $resp->item : [];

        if (empty($mediaList)) {
            self::log('素材库为空，无法获取封面 media_id', 'ERROR');
            throw new Exception('微信素材库为空，请先在公众号后台上传一张图片，或在插件设置中配置默认封面图 URL');
        }

        // 优先用名为 typecho.jpg 的图（兼容老逻辑）；否则取第一张
        $matching = null;
        foreach ($mediaList as $media) {
            if (isset($media->name) && $media->name === 'typecho.jpg') {
                $matching = $media;
                break;
            }
        }
        $media_id = $matching !== null ? $matching->media_id : $mediaList[0]->media_id;
        @file_put_contents($cacheFile, $media_id);
        return $media_id;
    }

    /**
     * 把远程 URL 的图片上传到微信永久素材库
     *
     * 走 material/add_material 接口（type=image），上传后返回的 media_id 是永久有效的，
     * 可以直接作为图文 thumb_media_id 使用。
     *
     * @param string $imageUrl 公网可访问的图片 URL
     * @return string|false 成功返回 media_id；失败返回 false
     */
    private static function uploadPermanentThumb($imageUrl)
    {
        // 下载到本地临时文件
        $tmpFile = self::downloadToTemp($imageUrl);
        if ($tmpFile === false) {
            self::log("封面图下载失败: {$imageUrl}", 'ERROR');
            return false;
        }
        self::log("封面图下载到: {$tmpFile}，大小: " . filesize($tmpFile) . " 字节");

        try {
            $accessToken = self::getAccessToken();
            // type=image 走永久素材库（永久有效，可用作 thumb_media_id）
            $url = 'https://api.weixin.qq.com/cgi-bin/material/add_material?access_token=' . $accessToken . '&type=image';
            $resp = self::curl($url, '', true, $tmpFile);
            @unlink($tmpFile);
            if (isset($resp->media_id)) {
                return $resp->media_id;
            }
            self::log('上传永久素材：响应无 media_id 字段: ' . json_encode($resp, JSON_UNESCAPED_UNICODE), 'ERROR');
            return false;
        } catch (Exception $e) {
            @unlink($tmpFile);
            self::log('上传永久素材异常: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    /* 上传图片到素材库
     *
     * 微信 media/uploadimg 接口要的是 multipart 文件，CURLFile 在大多数 PHP 环境下
     * 不接受 http(s) URL —— 远程图片必须先下载到本地临时文件。
     *
     * 单张图片失败时跳过、保留原 src，让其他图片继续上传，整体不中断。
     */
    public static function uploadImageToWeChat($html){
        // 预处理：把 Markdown 图片 ![alt](url) 先转为 <img> 标签
        // 这样即使上游给的 HTML 里混着 Markdown 也能正确处理
        $html = preg_replace('/!\[(.*?)\]\(([^)\s]+)\)/i', '<img src="$2" alt="$1">', $html);
        self::log("Markdown 图片预处理后，html 长度: " . strlen($html));

        $accessToken = self::getAccessToken();
        $url = 'https://api.weixin.qq.com/cgi-bin/media/uploadimg?access_token='.$accessToken;

        // 匹配所有的 <img> 标签
        preg_match_all('/<img[^>]+>/i', $html, $matches);
        $images = $matches[0];
        self::log("正文中找到 " . count($images) . " 个 <img> 标签");

        foreach ($images as $image) {
            // 提取 src 属性（双引号 / 单引号 / 无引号都支持）
            if (!preg_match('/src\s*=\s*["\']?([^"\'>\s]+)/i', $image, $srcMatches)) {
                self::log("跳过无 src 的 img 标签: {$image}", 'WARN');
                continue;
            }
            $src = $srcMatches[1];
            self::log("处理图片: {$src}");

            // 远程 URL 先落盘到 tmp，本地路径直接用
            $tmpFile = null;
            $uploadPath = $src;
            if (preg_match('#^https?://#i', $src)) {
                $tmpFile = self::downloadToTemp($src);
                if ($tmpFile === false) {
                    continue; // 下载失败，跳过这张图，保留原 src
                }
                $uploadPath = $tmpFile;
            }

            try {
                $res = self::curl($url, '', true, $uploadPath);
                if (isset($res->url)) {
                    $html = str_replace($src, $res->url, $html);
                }
            } catch (Exception $e) {
                // 单张图片上传失败不影响其他图片和草稿同步整体
            }

            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
        }

        return $html;
    }

    /**
     * 下载远程图片到临时文件
     *
     * 用 curl 抓取（比 file_get_contents 兼容性好，且能绕过 allow_url_fopen 限制）。
     * 返回 tmp 路径，调用方负责 unlink。失败返回 false。
     *
     * @param string $url
     * @return string|false
     */
    private static function downloadToTemp($url)
    {
        // 读出可调配置（一次取齐，避免多次 plugin() 调用）
        $cfg = self::getDownloadConfig();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $cfg['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $cfg['userAgent']);

        if (!empty($cfg['referer'])) {
            curl_setopt($ch, CURLOPT_REFERER, $cfg['referer']);
        }

        if (!empty($cfg['extraHeaders'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $cfg['extraHeaders']);
        }

        if (!empty($cfg['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $cfg['proxy']);
        }

        self::log("downloadToTemp 配置: " . json_encode([
            'referer'   => $cfg['referer'],
            'userAgent' => mb_substr($cfg['userAgent'], 0, 40, 'UTF-8'),
            'timeout'   => $cfg['timeout'],
            'proxy'     => $cfg['proxy'] ?: '(直连)',
            'headers'   => count($cfg['extraHeaders']),
        ], JSON_UNESCAPED_UNICODE));

        $data = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false) {
            self::log("downloadToTemp curl 错误: {$curlErr}", 'ERROR');
            return false;
        }
        if ($httpCode >= 400) {
            self::log("downloadToTemp HTTP {$httpCode}（可能被 CDN 防盗链拒绝；检查「下载图片时的 Referer」配置）", 'ERROR');
            return false;
        }
        if (strlen($data) < 100) {
            self::log("downloadToTemp 响应体过短 (" . strlen($data) . " 字节)，可能不是有效图片", 'ERROR');
            return false;
        }

        // 猜后缀（微信对扩展名敏感）：优先从 URL，兜底 .jpg
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            $ext = 'jpg';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wxdraft_') . '.' . $ext;
        if (file_put_contents($tmp, $data) === false) {
            return false;
        }
        return $tmp;
    }

    /**
     * 统一读取下载相关配置（带默认值兜底）
     *
     * @return array {
     *   referer: string,
     *   userAgent: string,
     *   extraHeaders: string[],
     *   timeout: int,
     *   proxy: string
     * }
     */
    private static function getDownloadConfig()
    {
        $setting = null;
        try {
            $setting = Helper::options()->plugin('WeChatDraft');
        } catch (Exception $e) {
            // 没 typecho 上下文也别炸
        }

        // Referer
        $referer = '';
        if ($setting !== null && !empty($setting->downloadReferer)) {
            $referer = $setting->downloadReferer;
        } else {
            try {
                $referer = Helper::options()->siteUrl;
            } catch (Exception $e) {}
        }

        // User-Agent
        $userAgent = ($setting !== null && !empty($setting->downloadUserAgent))
            ? $setting->downloadUserAgent
            : 'WeChatDraft/1.0';

        // 额外请求头（每行一个 Header: value）
        $extraHeaders = [];
        if ($setting !== null && !empty($setting->downloadExtraHeaders)) {
            foreach (preg_split('/\r?\n/', $setting->downloadExtraHeaders) as $line) {
                $line = trim($line);
                if ($line !== '' && strpos($line, ':') !== false) {
                    $extraHeaders[] = $line;
                }
            }
        }

        // 超时
        $timeout = ($setting !== null && !empty($setting->downloadTimeout))
            ? intval($setting->downloadTimeout)
            : 30;
        if ($timeout < 5 || $timeout > 300) {
            $timeout = 30;
        }

        // 代理
        $proxy = ($setting !== null && !empty($setting->downloadProxy))
            ? trim($setting->downloadProxy)
            : '';

        return [
            'referer'      => $referer,
            'userAgent'    => $userAgent,
            'extraHeaders' => $extraHeaders,
            'timeout'      => $timeout,
            'proxy'        => $proxy,
        ];
    }

    /**
     * Curl 请求
     * @param $url
     */
    public static function curl($url,$jsonData = '',$ispost = false,$imagePath ='')
    {
        // URL 脱敏（去掉 access_token 参数值）后打日志
        $safeUrl = preg_replace('/access_token=[^&]+/', 'access_token=***', $url);
        self::log("curl: {$safeUrl}" . ($ispost ? ' [POST]' : ' [GET]')
            . ($imagePath ? " [upload:{$imagePath}]" : ''));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 忽略 SSL 证书验证
        if ($ispost) {
            // POST 请求
            curl_setopt($ch, CURLOPT_POST, true);

            if (empty($imagePath)) {
                $postData = $jsonData;
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                ));
            } else {
                $postData = array(
                    'media' => new CURLFile($imagePath)
                );
            }
            // 设置请求体数据为 JSON 字符串
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

            // 设置请求头为 application/json

        }

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            self::log("curl 网络错误: {$curlErr} (HTTP {$httpCode})", 'ERROR');
            throw new Exception("网络请求失败: {$curlErr}");
        }

        // 截断长响应避免日志爆炸
        self::log("curl 响应 (HTTP {$httpCode}): " . mb_substr($response, 0, 500, 'UTF-8'));

        $responseData = json_decode($response);
        if ($responseData === null) {
            self::log("curl 响应非 JSON: " . mb_substr($response, 0, 200, 'UTF-8'), 'ERROR');
            throw new Exception("响应解析失败，非 JSON 格式");
        }
        // 微信侧 errcode=0 是成功；有些接口只在错误时才返回 errmsg 字段
        if (!isset($responseData->errmsg) || (isset($responseData->errcode) && intval($responseData->errcode) === 0)) {
            return $responseData;
        }
        // 请求失败，处理错误信息
        $errcode = isset($responseData->errcode) ? $responseData->errcode : '?';
        throw new Exception("微信API错误 [errcode={$errcode}]: " . $responseData->errmsg);
    }

    /* 插件实现方法 —— Widget_Contents_Post_Edit::finishPublish 钩子入口
     *
     * 这里的策略判断只影响"用户在后台手动发文"的场景。
     * 通过 syncDraft() 跨插件调用的链路是另一条入口，不经过这里。
     */
    public static function render($post, $obj){
        self::log('=== render() 被触发 ===');
        self::log('post 字段: ' . implode(',', array_keys($post)));
        self::log('text 长度: ' . strlen($post['text'] ?? ''));
        self::log('content 长度: ' . strlen($obj->content ?? ''));

        // 带密码的私密文章 / 过短的内容直接跳过（保留原行为）
        if (!empty($post['password'])) {
            self::log('跳过：文章带密码', 'WARN');
            return;
        }
        if (strlen($post['text']) <= 100) {
            self::log('跳过：text 长度 <= 100', 'WARN');
            return;
        }

        $setting = Helper::options()->plugin('WeChatDraft');
        $defaultAuto = is_array($setting->autoSyncDefault)
            && in_array('enable', $setting->autoSyncDefault);
        $keyword = !empty($setting->syncTriggerKeyword)
            ? trim($setting->syncTriggerKeyword)
            : 'wechat';
        self::log("配置: autoSyncDefault=" . ($defaultAuto ? 'true' : 'false') . ", keyword={$keyword}");

        $decision = self::decideSyncForManualPost($post, $obj, $defaultAuto, $keyword);
        self::log('策略判断: sync=' . ($decision['sync'] ? 'true' : 'false') . ', reason=' . $decision['reason']);
        if (!$decision['sync']) {
            return;
        }

        self::log('开始调用 syncDraft...');
        $result = self::syncDraft($obj->title, $obj->content, $obj->url);
        self::log('syncDraft 返回: success=' . ($result['success'] ? 'true' : 'false')
            . ', message=' . $result['message']
            . (isset($result['media_id']) ? ', media_id=' . $result['media_id'] : ''),
            $result['success'] ? 'INFO' : 'ERROR');
    }

    /**
     * 决定一篇手动发布的文章是否要同步
     *
     * 命中规则（任一即"标记命中"）：
     *   1. 正文出现 <!--{keyword}--> 注释 / <!--no-{keyword}-->
     *   2. 文章标签包含 {keyword} 或 no-{keyword}
     *   3. 文章分类包含 {keyword} 或 no-{keyword}
     *
     * 决策矩阵：
     *   opt-in 模式（autoSyncDefault=false）：命中正向标记才同步
     *   opt-out 模式（autoSyncDefault=true）：命中反向标记才跳过
     *
     * @param array  $post         finishPublish 钩子的 $post 数组
     * @param object $obj          finishPublish 钩子的 $obj（Widget_Contents_Post_Edit 实例）
     * @param bool   $defaultAuto  true=opt-out, false=opt-in
     * @param string $keyword      触发关键词
     * @return array ['sync' => bool, 'reason' => string]  reason 仅用于调试
     */
    public static function decideSyncForManualPost($post, $obj, $defaultAuto, $keyword)
    {
        $content = isset($obj->content) ? $obj->content : (isset($post['text']) ? $post['text'] : '');
        $positiveTag = strtolower($keyword);
        $negativeTag = 'no-' . $positiveTag;

        // 1) 正文注释（不区分大小写，允许标记前后有空白）
        $hasPositiveComment = self::contentHasMarker($content, $positiveTag);
        $hasNegativeComment = self::contentHasMarker($content, $negativeTag);

        // 2/3) 标签 + 分类一起收集
        $labels = self::collectLabels($obj, $post);
        $hasPositiveLabel = in_array($positiveTag, $labels, true);
        $hasNegativeLabel = in_array($negativeTag, $labels, true);

        if ($defaultAuto) {
            // opt-out 模式：默认发，命中任一反向标记则跳过
            if ($hasNegativeComment || $hasNegativeLabel) {
                return ['sync' => false, 'reason' => 'opt-out: marker matched'];
            }
            return ['sync' => true, 'reason' => 'opt-out default'];
        }

        // opt-in 模式：默认不发，命中任一正向标记才发
        if ($hasPositiveComment || $hasPositiveLabel) {
            return ['sync' => true, 'reason' => 'opt-in: marker matched'];
        }
        return ['sync' => false, 'reason' => 'opt-in default'];
    }

    /**
     * 在内容中查找 <!--{token}--> 形式的注释（容忍空白、不区分大小写）
     */
    private static function contentHasMarker($content, $token)
    {
        if ($content === '' || $token === '') {
            return false;
        }
        $pattern = '/<!--\s*' . preg_quote($token, '/') . '\s*-->/i';
        return (bool) preg_match($pattern, $content);
    }

    /**
     * 从 finishPublish 的入参里抠出标签 + 分类名（统一小写）
     *
     * Typecho 后台提交时 $post 里通常含 'tags'（逗号分隔）与 'category'（mid 数组）；
     * 不同版本字段名可能略有差异，能抠到就抠，抠不到就空着——本插件的策略判断
     * 不能因为反射失败而炸掉发文流程。
     */
    private static function collectLabels($obj, $post)
    {
        $labels = [];

        // 1) 直接读 $post 里的 tags 字符串（逗号分隔）
        if (!empty($post['tags'])) {
            $tagsRaw = is_array($post['tags']) ? implode(',', $post['tags']) : (string) $post['tags'];
            foreach (preg_split('/[,，]/', $tagsRaw) as $t) {
                $t = strtolower(trim($t));
                if ($t !== '') {
                    $labels[] = $t;
                }
            }
        }

        // 2) 分类：$post['category'] 是 mid 数组，需要查 metas 表拿 name/slug
        if (!empty($post['category'])) {
            $cids = is_array($post['category']) ? $post['category'] : [$post['category']];
            try {
                $db = Typecho_Db::get();
                foreach ($cids as $mid) {
                    $row = $db->fetchRow(
                        $db->select('name', 'slug')
                            ->from('table.metas')
                            ->where('mid = ?', intval($mid))
                            ->limit(1)
                    );
                    if ($row) {
                        if (!empty($row['name'])) $labels[] = strtolower($row['name']);
                        if (!empty($row['slug'])) $labels[] = strtolower($row['slug']);
                    }
                }
            } catch (Exception $e) {
                // 数据库访问失败不影响策略判断，当作无标签处理
            }
        }

        // 3) 兜底：obj->tags / obj->categories 如果存在（某些 Typecho 版本会塞进来）
        foreach (['tags', 'categories'] as $field) {
            if (isset($obj->{$field}) && is_array($obj->{$field})) {
                foreach ($obj->{$field} as $row) {
                    if (is_array($row)) {
                        if (!empty($row['name'])) $labels[] = strtolower($row['name']);
                        if (!empty($row['slug'])) $labels[] = strtolower($row['slug']);
                    } elseif (is_object($row)) {
                        if (!empty($row->name)) $labels[] = strtolower($row->name);
                        if (!empty($row->slug)) $labels[] = strtolower($row->slug);
                    } elseif (is_string($row)) {
                        $labels[] = strtolower(trim($row));
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($labels)));
    }

    /**
     * 公开的草稿同步入口
     *
     * 把一篇已 HTML 化的文章塞进微信公众号草稿箱。供 render() 内部使用，
     * 同时供其他插件（如 AiDailyPost 自动发文流程）跨插件调用。
     *
     * 异常一律转成返回值——不让上游因为微信侧故障而失败。
     *
     * @param string      $title      文章标题
     * @param string      $contentHtml 文章正文 HTML（不是 Markdown）
     * @param string      $sourceUrl  原文 URL（content_source_url）
     * @param string|null $authorOverride 覆盖配置/资料里的作者名，传 null 走默认
     * @return array ['success' => bool, 'message' => string, 'media_id' => string|null]
     */
    /**
     * 清理正文 HTML，为微信公众号草稿做准备：
     *   - 剥掉 <a> 标签（微信草稿 API 会剥离外链 href，链接不可点击）
     *   - 移除「原文链接 / 阅读原文 / 链接」等空洞引导词
     *   - 删除裸的「（链接）/(链接)」括号注释
     *   - 删除 <hr> 分隔线（在微信里渲染成虚线，破坏视觉节奏）
     *
     * 读者通过文章底部的「阅读原文」按钮回到博客原文。
     *
     * @param string $html
     * @return string
     */
    private static function stripLinksForWeChat($html)
    {
        // 微信草稿箱会剥离外链 href，导致链接不可点击。
        // 处理策略：
        //   1. 剥掉所有 <a> 标签，只保留显示文本（用户通过「阅读原文」按钮跳博客）
        //   2. 如果显示文本是「原文链接 / 查看原文 / 阅读原文 / 链接」等空洞引导词，
        //      整个删掉（这些词没了链接就完全没意义）；
        //      普通正文里出现的同样字眼不在 <a> 内，不受影响。
        //   3. 删除正文里裸的「（链接）」/「(链接)」括号注释（不在 <a> 内的纯文本）。
        //      —— 这些通常是源 HTML 自带的 URL 提示，剥外链后失去意义。
        //   4. 删除 <hr> 分隔线 —— 微信里渲染成虚线，破坏视觉节奏；公众号正文用段落
        //      留白分节即可。
        //   5. 清理因删链产生的空 <p> 和孤立标点。
        $emptyLinkTexts = ['原文链接', '原文', '查看原文', '阅读原文', '详情', '查看详情',
                           'source', 'link', '链接', '（链接）', '(链接)'];

        $html = preg_replace_callback(
            '/<a\s+[^>]*href="https?:\/\/[^"]*"[^>]*>([^<]*)<\/a>/i',
            function ($m) use ($emptyLinkTexts) {
                $text = trim($m[1]);
                if (in_array(strtolower($text), $emptyLinkTexts, true)) {
                    return '';
                }
                return $text;
            },
            $html
        );

        // 删除正文里裸的「（链接）」/「(链接)」括号注释（半角/全角括号都覆盖）
        $html = preg_replace('/[（(]\s*链接\s*[）)]/u', '', $html);

        // 删除 <hr> 分隔线（自闭合 / 带属性 / 闭合标签都覆盖）
        $html = preg_replace('/<hr\b[^>]*\/?>/i', '', $html);
        $html = preg_replace('/<\/hr>/i', '', $html);

        // 清理因删链留下的空段落
        $html = preg_replace('/<p>\s*<\/p>/u', '', $html);
        // 清理空 <li>（列表项里如果只有「原文链接」单独成项的情况）
        $html = preg_replace('/<li>\s*<\/li>/u', '', $html);

        return $html;
    }

    /**
     * 给正文 HTML 注入 inline style（微信公众号编辑器只认 inline style）
     *
     * 设计原则：
     *   1. 只补未设置的样式 —— 如果某个标签上已经有 style 属性，原值优先，
     *      插件追加的样式放在 style 字符串前面，被原有声明覆盖（CSS 后写优先）。
     *      这样不会破坏博客主题已经设定的视觉效果。
     *   2. 整体包一层 <section id="nice"> 设定全文基础字号/行高/字体，
     *      模仿 markdown.com.cn 编辑器的默认外观。
     *   3. 代码块仅做结构包装（外层 section + flex 布局），不引入 GeSHi 等
     *      高亮库 —— 代码颜色由微信公众号编辑器自身渲染。
     *
     * @param string $html       已 stripLinks 处理过的正文 HTML
     * @param string $themeColor CSS 颜色字符串
     * @return string 美化后的 HTML（外层带 section 壳）
     */
    private static function beautifyHtmlForWeChat($html, $themeColor)
    {
        if ($html === '' || $html === null) {
            return $html;
        }

        // 防御：主题色非法时回退默认值（避免污染 style 字符串）
        if ($themeColor === '' || preg_match('/[<>"\']/', $themeColor)) {
            $themeColor = 'hsl(216, 100%, 68%)';
        }

        // ========== 第 1 步：构造按标签的 style 表 ==========
        // 这里的样式参考了上游 TeohZY/WeChatDraft 的 CustomParsedown.php，
        // 在微信编辑器里实测过。padding/margin 数值保守，避免和博客主题打架。
        $styleMap = [
            'h1' => "font-size:1.7em;font-weight:normal;color:#333;"
                  . "border-bottom:2px solid {$themeColor};"
                  . "padding-bottom:6px;margin:30px 0 15px;",
            'h2' => "font-size:1.4em;font-weight:normal;color:#333;"
                  . "border-bottom:1px solid {$themeColor};"
                  . "padding-bottom:4px;margin:30px 0 15px;",
            'h3' => "font-size:1.2em;font-weight:normal;color:#333;margin:30px 0 15px;",
            'h4' => "font-size:1.1em;font-weight:bold;color:#333;margin:24px 0 12px;",
            'h5' => "font-size:1em;font-weight:bold;color:#333;margin:20px 0 10px;",
            'h6' => "font-size:1em;font-weight:normal;color:{$themeColor};"
                  . "border-bottom:1px solid {$themeColor};"
                  . "padding-bottom:2px;margin:20px 0 10px;",
            'p'  => "font-size:16px;padding:8px 0;margin:0;line-height:26px;color:#333;",
            'ul' => "margin:8px 0;color:#333;list-style-type:disc;padding-left:2em;",
            'ol' => "margin:8px 0;color:#333;list-style-type:decimal;padding-left:2em;",
            'li' => "margin:4px 0;line-height:26px;",
            'blockquote' => "background:#f9f9f9;border-left:4px solid {$themeColor};"
                          . "padding:10px 16px;margin:15px 0;color:#555;",
            'table' => "display:table;text-align:left;margin:1.5em auto;width:auto;"
                     . "border-collapse:collapse;font-size:15px;",
            'thead' => "background:#fafafa;",
            'th' => "border:1px solid #ccc;padding:6px 12px;text-align:center;"
                  . "font-weight:bold;color:#333;",
            'td' => "border:1px solid #ccc;padding:6px 12px;color:#555;",
            'strong' => "color:{$themeColor};",
        ];

        // ========== 第 2 步：加载 HTML 到 DOM ==========
        // 用 XML 声明（`<` + `?xml encoding="UTF-8"?` + `>`，不在这里写完整字面量
        // 以免提前关闭 PHP 标签）告诉 DOMDocument 内容是 UTF-8，
        // 避免在 PHP 8.2+ 上调用已弃用的 mb_convert_encoding(..., 'HTML-ENTITIES')。
        // 用一个已知的根包裹（wxbeautify-root）便于后面精确提取 inner HTML。
        $dom = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"?><div id="wxbeautify-root">' . $html . '</div>';
        $loaded = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            self::log('beautifyHtmlForWeChat: DOM 加载失败，回退到原始 HTML', 'WARN');
            return $html;
        }

        // ========== 第 3 步：按标签批量注入样式 ==========
        foreach ($styleMap as $tag => $style) {
            $nodes = $dom->getElementsByTagName($tag);
            // 把 NodeList 物化成数组：getElementsByTagName 是 live 的，
            // 边遍历边改属性也没问题（不影响节点列表本身），但留个保险。
            $list = [];
            foreach ($nodes as $n) {
                $list[] = $n;
            }
            foreach ($list as $el) {
                self::mergeInlineStyle($el, $style);
            }
        }

        // 行内 code：只处理不在 <pre> 里的 <code>。<pre><code> 在第 4 步统一处理。
        foreach ($dom->getElementsByTagName('code') as $code) {
            if ($code->parentNode && strtolower($code->parentNode->nodeName) === 'pre') {
                continue;
            }
            self::mergeInlineStyle(
                $code,
                "font-size:14px;padding:2px 6px;border-radius:4px;margin:0 2px;"
                . "background-color:rgba(27,31,35,.05);color:{$themeColor};"
                . "font-family:Consolas,Monaco,Menlo,monospace;word-break:break-all;"
            );
        }

        // ========== 第 4 步：代码块包装 ==========
        // 把 <pre><code class="language-xx">…</code></pre> 替换成微信公众号编辑器
        // 同款两栏结构：左侧 <ul>(行号占位) + 右侧 <pre>。不做语法高亮，让微信
        // 编辑器或读者的客户端原生渲染等宽字体即可。
        $preList = [];
        foreach ($dom->getElementsByTagName('pre') as $pre) {
            $preList[] = $pre;
        }
        foreach ($preList as $pre) {
            self::wrapCodeBlock($dom, $pre, $themeColor);
        }

        // ========== 第 5 步：提取 inner HTML，并包外层 section 壳 ==========
        $root = $dom->getElementById('wxbeautify-root');
        if ($root === null) {
            // 极少情况：DOM 没找到根，回退到原 HTML
            self::log('beautifyHtmlForWeChat: 未找到 wxbeautify-root，回退', 'WARN');
            return $html;
        }
        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }

        // 外层 section 壳：参考 markdown.com.cn 编辑器的 nice 主题。
        // text-align:justify + word-break:break-all 适合中英文混排。
        $shellStyle = 'font-size:16px;color:#333;padding:20px 16px;'
                    . 'line-height:1.7;word-spacing:0;letter-spacing:0;'
                    . 'word-wrap:break-word;text-align:justify;'
                    . "font-family:'PingFang SC','Microsoft YaHei',sans-serif;"
                    . 'word-break:break-all;';

        return '<section id="nice" data-tool="WeChatDraft" style="' . $shellStyle . '">'
             . $inner
             . '</section>';
    }

    /**
     * 合并 inline style：插件追加的样式放前面，原有声明放后面
     *
     * 这样如果原 HTML 上的 style 和插件 styleMap 里有同名属性，原有声明会覆盖
     * 插件的（CSS 同优先级后写胜出），既不破坏博客主题，又能在原本没设的属性上
     * 补足美化。
     *
     * @param DOMElement $el
     * @param string     $appendStyle 插件想注入的 style 片段（以分号结尾或不结尾都行）
     */
    private static function mergeInlineStyle(DOMElement $el, $appendStyle)
    {
        $appendStyle = rtrim(trim($appendStyle), ';');
        if ($appendStyle === '') {
            return;
        }
        $existing = $el->hasAttribute('style') ? trim($el->getAttribute('style')) : '';
        if ($existing === '') {
            $el->setAttribute('style', $appendStyle . ';');
            return;
        }
        // 插件样式放前 → 原有样式放后（同名属性原值生效）
        $existing = rtrim($existing, ';');
        $el->setAttribute('style', $appendStyle . ';' . $existing . ';');
    }

    /**
     * 把 <pre><code> 块替换成微信编辑器同款的 code-snippet 两栏结构
     *
     * 输入形如：
     *   <pre><code class="language-php">echo "hi";\n echo "bye";</code></pre>
     * 输出形如：
     *   <section class="code-snippet__fix code-snippet__js" style="...">
     *     <ul class="code-snippet__line-index code-snippet__js">
     *       <li></li><li></li>...
     *     </ul>
     *     <pre data-lang="php" class="code-snippet__js">
     *       <code>echo "hi";</code><code>echo "bye";</code>
     *     </pre>
     *   </section>
     *
     * 微信公众号编辑器识别这个结构，会按代码块样式渲染（等宽字体 + 行号竖条）。
     *
     * @param DOMDocument $dom
     * @param DOMElement  $pre   原 <pre> 节点
     * @param string      $themeColor
     */
    private static function wrapCodeBlock(DOMDocument $dom, DOMElement $pre, $themeColor)
    {
        // 先提取语言和内部代码文本
        // 兼容两种形态：<pre><code class="language-xx">…</code></pre>  和  <pre>裸文本</pre>
        $language = 'plaintext';
        $codeText = '';
        $innerCode = null;
        foreach ($pre->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'code') {
                $innerCode = $child;
                break;
            }
        }
        if ($innerCode !== null) {
            // 从 class="language-xx" 抠语言
            if ($innerCode->hasAttribute('class')) {
                if (preg_match('/language-([a-zA-Z0-9_+\-]+)/', $innerCode->getAttribute('class'), $m)) {
                    $language = $m[1];
                }
            }
            $codeText = $innerCode->textContent;
        } else {
            $codeText = $pre->textContent;
        }

        // 按行切分（保留空行结构）
        $codeText = str_replace(["\r\n", "\r"], "\n", $codeText);
        $lines = explode("\n", $codeText);
        // 末尾如果是空行，扔掉以免多一个空 <code>
        if (count($lines) > 0 && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }
        if (count($lines) === 0) {
            $lines = [''];
        }

        // 构造外层 section
        $section = $dom->createElement('section');
        $section->setAttribute('class', 'code-snippet__fix code-snippet__js');
        $section->setAttribute('style',
            'margin:10px 0;text-align:left;font-weight:500;font-size:14px;'
            . 'display:flex;color:#333;position:relative;'
            . 'background-color:rgba(0,0,0,0.03);'
            . 'border:1px solid #f0f0f0;border-radius:4px;'
            . 'line-height:20px;word-wrap:break-word;'
            . 'overflow-x:auto;'
        );

        // 左侧行号 <ul>
        $ul = $dom->createElement('ul');
        $ul->setAttribute('class', 'code-snippet__line-index code-snippet__js');
        $ul->setAttribute('style',
            'list-style:none;margin:0;padding:0 8px 0 12px;'
            . 'border-right:1px solid #eee;color:#999;'
            . 'min-width:24px;text-align:right;'
            . 'font-family:Consolas,Monaco,Menlo,monospace;'
        );
        for ($i = 0; $i < count($lines); $i++) {
            $li = $dom->createElement('li');
            $li->setAttribute('style', 'list-style:none;');
            $ul->appendChild($li);
        }

        // 右侧代码 <pre>
        $newPre = $dom->createElement('pre');
        $newPre->setAttribute('class', 'code-snippet__js');
        $newPre->setAttribute('data-lang', $language);
        $newPre->setAttribute('style',
            'margin:0;padding:8px 12px;flex:1;'
            . 'font-family:Consolas,Monaco,Menlo,monospace;'
            . 'font-size:14px;line-height:20px;'
            . 'white-space:pre;overflow-x:auto;'
            . 'background:transparent;'
        );
        foreach ($lines as $line) {
            $codeEl = $dom->createElement('code');
            $codeEl->setAttribute('style', 'display:block;white-space:pre;');
            // textContent 由 DOM 自动转义，确保 < & 之类不会破坏结构
            $codeEl->appendChild($dom->createTextNode($line === '' ? ' ' : $line));
            $newPre->appendChild($codeEl);
        }

        $section->appendChild($ul);
        $section->appendChild($newPre);

        // 用新的 section 替换原 <pre>
        $pre->parentNode->replaceChild($section, $pre);
    }

    /**
     * 解析封面图 media_id
     *
     * 由「封面图优先策略」决定顺序：
     *   article_first（默认）：文章首图 → 默认封面 URL → 素材库第一张
     *   default_first       ：默认封面 URL → 文章首图 → 素材库第一张
     *
     * @param string $firstImageUrl 从原始 HTML 中抠出的首图 URL（可能为空）
     * @return string media_id
     */
    private static function resolveThumbMediaId($firstImageUrl)
    {
        $setting = Helper::options()->plugin('WeChatDraft');
        $strategy = isset($setting->coverStrategy) ? $setting->coverStrategy : 'article_first';
        $defaultThumbUrl = isset($setting->defaultThumbUrl) ? trim($setting->defaultThumbUrl) : '';

        // 策略 A: 默认封面优先
        if ($strategy === 'default_first' && $defaultThumbUrl !== '') {
            self::log("封面策略=default_first，优先使用默认封面 URL");
            try {
                return self::getMediaId(); // 内部已带「默认封面 URL → 素材库」逻辑
            } catch (Exception $e) {
                self::log("默认封面获取失败：" . $e->getMessage() . "，降级到文章首图", 'WARN');
            }
            // 默认封面拿不到 → 降级到文章首图
            if ($firstImageUrl !== '') {
                $mediaId = self::uploadPermanentThumb($firstImageUrl);
                if ($mediaId !== false) {
                    self::log("降级用文章首图作为封面成功: {$mediaId}");
                    return $mediaId;
                }
            }
            // 都没成功 → 抛给 getMediaId 走素材库兜底
            return self::getMediaId();
        }

        // 策略 B: 文章首图优先（默认）
        if ($firstImageUrl !== '') {
            self::log("封面策略=article_first，尝试用文章首图作为封面: {$firstImageUrl}");
            $mediaId = self::uploadPermanentThumb($firstImageUrl);
            if ($mediaId !== false) {
                self::log("文章首图作为封面成功: {$mediaId}");
                return $mediaId;
            }
            self::log("文章首图上传永久素材失败，降级到默认封面", 'WARN');
        }

        // 没有文章首图 / 上传失败 → 默认封面 URL / 素材库第一张
        return self::getMediaId();
    }

    /**
     * 从 HTML 中提取第一张图片的 src
     *
     * @param string $html
     * @return string 空字符串表示没图
     */
    private static function extractFirstImageUrl($content)
    {
        // HTML 格式：双引号 / 单引号 / 无引号都能匹配
        if (preg_match('/<img[^>]+src\s*=\s*["\']?([^"\'>\s]+)/i', $content, $m)) {
            return trim($m[1]);
        }
        // Markdown 格式 ![alt](url)
        if (preg_match('/!\[.*?\]\(([^)\s]+)/', $content, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * 从文章 HTML 生成公众号摘要（digest）
     *
     * 微信草稿 API 的 digest 字段最长 120 个汉字。不传时微信会自动从正文截取，
     * 但截出来常带 HTML/markdown 残渣。主动传一个干净的纯文本摘要更可控。
     *
     * 清洗顺序（每一步都为了去掉一类不该出现在摘要里的字符）：
     *   1. 把 markdown 图片 ![alt](url) 收掉 —— 留 alt（通常更有意义）
     *   2. 把 markdown 链接 [text](url) 留 text，丢 url
     *   3. strip_tags 剥掉所有 HTML 标签
     *   4. 解 HTML 实体（&nbsp; &amp; &lt; 等还原成字符）
     *   5. 合并所有空白字符（含中文全角空格 U+3000、换行、tab）为单空格
     *   6. mb_substr 按 UTF-8 字符截取，避免切到半个汉字；若窗口内存在句末标点
     *      （中文 。！？ / 英文 .!?），退回到最后一个句末标点处，保证摘要以完整句子结尾
     *
     * @param string $contentHtml 原始文章 HTML
     * @param int    $maxLen      最大长度（汉字数），默认 120 对齐微信限制
     * @return string 纯文本摘要
     */
    private static function generateDigest($contentHtml, $maxLen = 120)
    {
        if ($contentHtml === '' || $contentHtml === null) {
            return '';
        }
        $text = $contentHtml;

        // 1) markdown 图片 → 保留 alt
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        // 2) markdown 链接 → 保留 text
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        // 3) HTML 标签
        $text = strip_tags($text);
        // 4) HTML 实体（ENT_QUOTES 同时处理单双引号；HTML5 支持新实体如 &check;）
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 5) 空白合并：\s 在 /u 模式下涵盖所有 Unicode 空白；额外加 \x{3000} 兜底全角空格
        $text = preg_replace('/[\s\x{3000}]+/u', ' ', $text);
        $text = trim($text);
        // 6) 按汉字数截取；如果超长，优先在最后一个句末标点处收尾，避免半截句子。
        //    中文 。！？ 直接计数；英文 .!? 仅当后面是空白或字符串末尾时才算句末，
        //    避免把小数点 / 缩写中的点当成句号（"3.14" / "U.S."）
        if (mb_strlen($text, 'UTF-8') > $maxLen) {
            $text = mb_substr($text, 0, $maxLen, 'UTF-8');
            if (preg_match_all('/[。！？]|[.!?](?=\s|$)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
                $last = end($m[0]);
                // PREG_OFFSET_CAPTURE 是字节偏移，而句末标点本身就在 UTF-8 字符边界上，
                // 在其末尾切字符串不会切到半个字符，可以直接用 substr
                $text = substr($text, 0, $last[1] + strlen($last[0]));
            }
        }
        return $text;
    }

    public static function syncDraft($title, $contentHtml, $sourceUrl, $authorOverride = null)
    {
        self::log("syncDraft 入参: title={$title}, sourceUrl={$sourceUrl}, html长度=" . strlen($contentHtml));
        try {
            $setting = Helper::options()->plugin('WeChatDraft');
            if (empty($setting->appid) || empty($setting->secret)) {
                self::log('AppID 或 Secret 为空', 'ERROR');
                return ['success' => false, 'message' => 'WeChatDraft 未配置 AppID/Secret', 'media_id' => null];
            }
            self::log('AppID 前4位: ' . substr($setting->appid, 0, 4) . '***');

            // 决定作者：调用方覆盖 > 配置 > Typecho 用户昵称
            //
            // 调试帮助：把 $setting->author 的原始值原样打出来。
            // 如果这里看到的是空、null、或者纯空白，说明配置没读到 —— 去 typecho 后台
            // 重新保存一次插件配置；如果看到的是预期的名字但 WeChat 草稿仍然不对，
            // 那就是微信侧的事（公众号名可能盖掉 author 字段，参见微信草稿 API 文档）。
            self::log('原始 $setting->author = ' . var_export(isset($setting->author) ? $setting->author : null, true));
            $configuredAuthor = isset($setting->author) ? trim((string)$setting->author) : '';
            if ($authorOverride !== null && $authorOverride !== '') {
                $author = $authorOverride;
                self::log("作者来源: authorOverride 参数");
            } elseif ($configuredAuthor !== '') {
                $author = $configuredAuthor;
                self::log("作者来源: 插件配置 (setting->author)");
            } else {
                try {
                    $user = Typecho_Widget::widget('Widget_User');
                    $author = $user->screenName;
                    self::log("作者来源: 当前登录用户 screenName (配置为空时的兜底)");
                } catch (Exception $e) {
                    $author = '';
                    self::log("作者来源: 兜底失败，置空", 'WARN');
                }
            }
            // 微信侧作者字段限 8 个汉字
            if (mb_strlen($author, 'UTF-8') > 8) {
                $author = mb_substr($author, 0, 8, 'UTF-8');
            }
            self::log("作者: {$author}");

            self::log('步骤 1/4: 获取 access_token...');
            $accessToken = self::getAccessToken();
            self::log('access_token 已获取: ' . substr($accessToken, 0, 8) . '***');

            self::log('步骤 2/4: 处理正文图片 + 决定封面...');
            // 先抠出原始首图 URL（用博客侧 URL 上传到永久素材库，最可靠）
            $originalFirstImg = self::extractFirstImageUrl($contentHtml);
            if ($originalFirstImg !== '') {
                self::log("正文首图（原始 URL）: {$originalFirstImg}");
            } else {
                self::log("正文无图片");
            }

            // 剥外链 + 清空洞文本
            $html = self::stripLinksForWeChat($contentHtml);

            // 排版美化（可选）：给标题/段落/列表/表格/blockquote/code 注入 inline style
            // 必须在 uploadImageToWeChat 之前 —— 美化后正文 <img> 仍保留原始 src，
            // 后续才能正确替换成微信侧的 mmbiz.qpic.cn 链接。
            if (!empty($setting->beautifyHtml) && is_array($setting->beautifyHtml)
                && in_array('enable', $setting->beautifyHtml, true)) {
                $themeColor = !empty($setting->beautifyThemeColor)
                    ? trim($setting->beautifyThemeColor)
                    : 'hsl(216, 100%, 68%)';
                self::log("排版美化已启用，主题色: {$themeColor}");
                $html = self::beautifyHtmlForWeChat($html, $themeColor);
                self::log('美化后 html 长度: ' . strlen($html));
            }

            // 处理正文 <img>（远程下载 → 上传到微信 → 替换 src 为 mmbiz.qpic.cn 临时图片地址）
            $html = self::uploadImageToWeChat($html);
            self::log('正文图片处理完成，最终 html 长度: ' . strlen($html));

            // 封面图策略：优先用文章首图（上传到永久素材库）；找不到再用默认封面
            $mediaId = self::resolveThumbMediaId($originalFirstImg);
            self::log("最终 thumb_media_id: {$mediaId}");

            // 生成摘要：从原始 HTML（不是美化/上传图片后的版本）洗出纯文本，
            // 保证摘要忠实于作者内容，不会带 beautify 引入的装饰元素。
            $digest = self::generateDigest($contentHtml, 120);
            self::log("摘要（" . mb_strlen($digest, 'UTF-8') . " 字）: {$digest}");

            self::log('步骤 3/4: 提交草稿到微信...');
            $url = 'https://api.weixin.qq.com/cgi-bin/draft/add?access_token=' . $accessToken;
            $payload = [
                'articles' => [[
                    'title'              => $title,
                    'author'             => $author,
                    'digest'             => $digest,
                    'content'            => $html,
                    'content_source_url' => $sourceUrl,
                    'thumb_media_id'     => $mediaId,
                ]],
            ];
            $resp = self::curl($url, json_encode($payload, JSON_UNESCAPED_UNICODE), true);
            self::log('微信响应: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));

            // curl() 成功路径下返回的对象一般含 media_id；失败路径已经在内部 throw 了
            return [
                'success'  => true,
                'message'  => '微信草稿同步成功',
                'media_id' => isset($resp->media_id) ? $resp->media_id : null,
            ];
        } catch (Exception $e) {
            self::log('syncDraft 捕获异常: ' . $e->getMessage(), 'ERROR');
            self::log('异常位置: ' . $e->getFile() . ':' . $e->getLine(), 'ERROR');
            return [
                'success'  => false,
                'message'  => '微信草稿同步失败: ' . $e->getMessage(),
                'media_id' => null,
            ];
        }
    }
}

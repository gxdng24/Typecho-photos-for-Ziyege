<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 子夜歌双视图相册
 *
 * @版本号:     v1.0.2
 * @作者:       子夜歌
 * @更新日期:    2026-04-04
 * @GitHub:     https://github.com/ziyege/typecho-ziyege-photo
 * @license     MIT
 *
 * ========== 功能特性 ==========
 * - 双视图切换：首页展示文章封面列表，详情页展示单篇文章所有图片
 * - 支持行内式和引用式 Markdown 图片
 * - Masonry 瀑布流布局，响应式适配
 * - Magnific Popup 灯箱，支持左右导航、标题显示
 * - 完全响应式，基于 Bootstrap 5
 * - 图片原生懒加载（loading="lazy"），淡入效果
 * - 安全过滤：过滤非 http/https/data:image 的图片 URL
 *
 * ========== 使用前必读 ==========
 * 1. 修改默认分类ID：将下方 `$category_id = 3;` 改为你的相册分类ID。或者在建立相册页面时增加category_id自定义字段。
 * 2. 云存储域名配置：在 getThumbnailUrl 函数中，将占位域名（如“你的七牛域名.com”）替换为你的实际绑定域名。
 *    - 若不使用云存储，缩略图功能将失效（返回原图），可忽略此项。
 * 3. 缩略图尺寸可根据需要调整：
 *    - 首页封面：getThumbnailUrl($url, 400, 300, true)  —— 400x300 裁剪
 *    - 详情页列表：getThumbnailUrl($url, 600, 0)        —— 宽度 600px 等比缩放
 *
 * @package custom
 */

// ----------------------------------------------------------------------
// 辅助函数定义
// ----------------------------------------------------------------------

/**
 * 生成随机图片URL（用于分类卡片背景）
 * 若主题已存在同名函数，则不再重复定义
 */
if (!function_exists('getRandImg')) {
    function getRandImg() {
        // 使用 picsum 随机图片（可替换为你自己的图库）
        return 'https://picsum.photos/800/400?random=' . mt_rand();
    }
}

/**
 * 生成缩略图 URL（支持七牛、又拍云、阿里云 OSS、缤纷云等）
 *
 * @param string $url     原图 URL
 * @param int    $width   宽度
 * @param int    $height  高度，传 0 表示等比缩放（宽度固定，高度自适应）
 * @param bool   $crop    是否裁剪（仅当 $height > 0 时有效）
 * @return string 处理后的缩略图 URL，若不支持则返回原图
 */
function getThumbnailUrl($url, $width = 400, $height = 0, $crop = false) {
    if (empty($url)) return $url;
    $url = str_replace(' ', '%20', $url);

    /*// 1. 七牛云存储（需开启图片处理）
    if (strpos($url, '你的七牛域名.com') !== false) {
        if ($height > 0) {
            $mode = $crop ? '1' : '2'; // 1:裁剪, 2:缩放
            return $url . "?imageView2/{$mode}/w/{$width}/h/{$height}";
        } else {
            // 高度为0，只指定宽度等比缩放
            return $url . "?imageView2/2/w/{$width}";
        }
    }

    // 2. 又拍云存储
    if (strpos($url, '你的又拍云域名.com') !== false) {
        if ($height > 0) {
            return $url . "!/both/{$width}x{$height}"; // 裁剪
        } else {
            return $url . "!/fw/{$width}";            // 等比缩放宽度
        }
    }

    // 3. 阿里云 OSS
    if (strpos($url, '你的OSS域名.com') !== false) {
        $ossParams = "x-oss-process=image/resize";
        if ($height > 0 && $crop) {
            $ossParams .= ",m_fixed,w_{$width},h_{$height}"; // 固定宽高裁剪
        } elseif ($height > 0 && !$crop) {
            $ossParams .= ",m_lfit,w_{$width},h_{$height}";  // 等比缩放（不大于指定宽高）
        } else {
            $ossParams .= ",w_{$width}";                     // 只指定宽度等比缩放
        }
        return $url . '?' . $ossParams;
    }*/

    // 4. 缤纷云存储（示例，根据实际参数调整）
    if (strpos($url, 'cdn.ziyege.com') !== false) {
        $params = "w={$width}";
        if ($height > 0) {
            $params .= "&h={$height}";
            if ($crop) $params .= "&mode=crop";
        }
        return $url . '?' . $params;
    }

    // 5. 其他情况（本地附件或外链）——无法生成缩略图，返回原图
    return $url;
}

/**
 * 过滤不安全的图片 URL
 * 只允许 http://, https://, data:image/, 以 / 开头的相对路径，以及 // 开头的协议相对 URL
 *
 * @param string $url 原始 URL
 * @return string 安全的 URL，若无效则返回空字符串
 */
function safeImageUrl($url) {
    $url = trim($url);
    // 允许 http://, https://, data:image/, 以 / 开头的相对路径
    if (preg_match('/^(https?:|data:image|\/)/i', $url)) {
        return $url;
    }
    // 允许 // 开头的协议相对 URL（自动补上 https:）
    if (strpos($url, '//') === 0) {
        return 'https:' . $url;
    }
    return '';
}

// ----------------------------------------------------------------------
// 初始化页面参数
// ----------------------------------------------------------------------

// 获取 URL 参数 post_id，决定当前是首页还是详情页
$post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 12; // 每页12篇/张
$pageMode = $post_id ? 'post' : 'home';
$pagination = null;

// ----------------------------------------------------------------------
// 辅助函数：从 Markdown 提取图片
// ----------------------------------------------------------------------

/**
 * 从 Markdown 文本中提取所有图片（支持引用式和行内式）
 *
 * @param string $text 文章内容（Markdown 格式）
 * @return array 图片数组，每个元素包含 ['title' => alt 文本, 'url' => 图片 URL]
 */
function extractImagesFromMarkdown($text) {
    $images = [];
    $definitions = [];

    // 1. 提取引用定义 [id]: url
    // 匹配格式： [id]: http://example.com/image.jpg "可选标题"
    preg_match_all('/^\s*\[([^\]]+)\]:\s*(\S+)(?:\s+(?:"|\')([^"\'"]+)(?:"|\'))?\s*$/m', $text, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $id = trim($match[1]);
        $url = trim($match[2]);
        $definitions[$id] = $url;
    }

    // 2. 提取引用式图片 ![alt][id]
    preg_match_all('/!\[([^\]]*)\]\[([^\]]+)\]/', $text, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $alt = trim($match[1]);
        $id  = trim($match[2]);
        if (isset($definitions[$id])) {
            $images[] = [
                'title' => $alt ?: '无标题',
                'url'   => $definitions[$id]
            ];
        }
    }

    // 3. 提取行内式图片 ![alt](url)
    preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $text, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $alt = trim($match[1]);
        $url = trim($match[2]);
        $images[] = [
            'title' => $alt ?: '无标题',
            'url'   => $url
        ];
    }

    return $images;
}

// ----------------------------------------------------------------------
// 获取数据库连接
// ----------------------------------------------------------------------

$db = Typecho_Db::get();

// ----------------------------------------------------------------------
// 根据页面模式获取数据
// ----------------------------------------------------------------------

$initialData = []; // 存储将要传递给前端的数据（JSON 格式）
$pageTitle   = ''; // 页面标题

if ($pageMode === 'home') {
    // ---------- 首页模式：获取指定分类下的文章封面 ----------
    // 获取分类 ID：优先使用自定义字段，否则默认 3（请根据实际情况修改）
    $category_id = 3; // 默认分类 ID，使用前请改为你的相册分类 ID
    if (isset($this->fields->category_id) && is_numeric($this->fields->category_id)) {
        $category_id = intval($this->fields->category_id);
    }
    // 确保分类 ID 为正整数
    if ($category_id <= 0) {
        $category_id = 3; // 如果非法则回退到默认
    }

    // 查询该分类下已发布的文章（按时间倒序）
    $posts = $db->fetchAll($db->select('table.contents.cid, table.contents.title, table.contents.text')
        ->from('table.relationships')
        ->join('table.contents', 'table.contents.cid = table.relationships.cid', Typecho_Db::INNER_JOIN)
        ->where('table.relationships.mid = ?', $category_id)
        ->where('table.contents.type = ?', 'post')
        ->where('table.contents.status = ?', 'publish')
        ->order('table.contents.created', Typecho_Db::SORT_DESC));

    $allArticles = [];
foreach ($posts as $post) {
 $articleImages = extractImagesFromMarkdown($post['text']);
 $safeImages = [];
 foreach ($articleImages as $img) {
 $safeUrl = safeImageUrl($img['url']);
 if ($safeUrl !== '') {
 $img['url'] = $safeUrl;
 $safeImages[] = $img;
 }
 }
 if (count($safeImages) > 0) {
 $firstImg = $safeImages[0];
 $allArticles[] = [
 'type' => 'article',
 'title' => $post['title'],
 'cover' => getThumbnailUrl($firstImg['url'], 400, 300, true),
 'imageCount' => count($safeImages),
 'postId' => $post['cid']
 ];
 }
}

$totalCount = count($allArticles);
$totalPages = ceil($totalCount / $perPage);
$offset = ($page - 1) * $perPage;
$initialData = array_slice($allArticles, $offset, $perPage);

$pagination = [
 'current' => $page,
 'total' => $totalPages,
 'count' => $totalCount
];

$pageTitle = '相册 - ' . $this->options->title;

} else {
    // ---------- 详情模式：获取单篇文章的所有图片 ----------
    $post = $db->fetchRow($db->select('title, text')
        ->from('table.contents')
        ->where('cid = ?', $post_id)
        ->where('type = ?', 'post')
        ->where('status = ?', 'publish'));

    if ($post) {
 $images = extractImagesFromMarkdown($post['text']);
 $safeImages = [];
 foreach ($images as $img) {
 $safeUrl = safeImageUrl($img['url']);
 if ($safeUrl === '') continue;
 $safeImages[] = [
 'type' => 'image',
 'title' => $img['title'],
 'desc' => $post['title'],
 'url' => $safeUrl,
 'thumb_url' => getThumbnailUrl($safeUrl, 600, 0)
 ];
 }
 
 $totalCount = count($safeImages);
 $totalPages = ceil($totalCount / $perPage);
 $offset = ($page - 1) * $perPage;
 $initialData = array_slice($safeImages, $offset, $perPage);
 
 $pagination = [
 'current' => $page,
 'total' => $totalPages,
 'count' => $totalCount,
 'postId' => $post_id
 ];
 
 $pageTitle = htmlspecialchars($post['title']) . ' - 图片详情';
 } else {
 // 文章不存在，跳转回首页
        header('Location: ' . $this->options->siteUrl);
        exit;
    }
}
?>
<!DOCTYPE HTML>
<html lang="zh-CN">
<head>
 <title><?php echo htmlspecialchars($pageTitle); ?></title>
 <meta charset="utf-8" />
 <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
 
 <?php if ($pageMode === 'home'): ?>
 <!-- 首页 SEO -->
 <meta name="description" content="<?php echo htmlspecialchars($this->options->title); ?>相册 - 浏览所有图片集合" />
 <meta property="og:title" content="<?php echo htmlspecialchars($this->options->title); ?> - 相册" />
 <meta property="og:description" content="浏览<?php echo htmlspecialchars($this->options->title); ?>的所有图片集合" />
 <meta property="og:type" content="website" />
 <meta property="og:url" content="<?php echo $this->permalink(); ?>" />
 <?php else: ?>
 <!-- 详情页 SEO -->
 <meta name="description" content="<?php echo htmlspecialchars($post['title']); ?> - 共<?php echo $totalCount; ?>张图片" />
 <meta property="og:title" content="<?php echo htmlspecialchars($post['title']); ?> - 图片详情" />
 <meta property="og:description" content="<?php echo htmlspecialchars($post['title']); ?> - 共<?php echo $totalCount; ?>张图片" />
 <meta property="og:type" content="article" />
 <meta property="og:url" content="<?php echo $this->permalink(); ?>?post_id=<?php echo $post_id; ?>" />
 <?php if (!empty($initialData)): ?>
 <meta property="og:image" content="<?php echo htmlspecialchars($initialData[0]['url']); ?>" />
 <?php endif; ?>
 <?php endif; ?>

    <!-- Bootstrap 5 核心 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Magnific Popup 灯箱 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/magnific-popup.js/1.1.0/magnific-popup.min.css">

    <!-- 自定义样式 -->
    <style>
        /* ---------- 加载指示器 ---------- */
        #loading-indicator {
            text-align: center;
            padding: 2rem;
            display: none;
        }
        #loading-indicator.show {
            display: block;
        }

        /* ---------- 卡片容器 ---------- */
        .thumb {
            position: relative;
            display: block;
            text-decoration: none;
            color: inherit;
            margin-bottom: 1rem;
            border-radius: 0;               /* 直角风格 */
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s;
            background-color: #f0f0f0;       /* 图片加载前的占位背景 */
        }
        .thumb:hover {
            transform: scale(1.02);
        }

        /* ---------- 图片样式：宽度100%，高度自适应，保持原比例 ---------- */
 .thumb img {
 width: 100%;
 height: auto;
 display: block;
 transition: opacity 0.3s ease;
 opacity: 0;
 background-color: #f0f0f0;
 min-height: 150px;
 }
        .thumb img.loaded {
            opacity: 1;
        }
        /* 详情页图片最小高度，防止布局坍塌 */
        .thumb img.img-detail {
            min-height: 200px;
        }

        /* ---------- 全屏遮罩（悬停时出现） ---------- */
        .thumb .mask {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.3);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;              /* 让鼠标可以穿透点击图片 */
        }
        .thumb:hover .mask {
            opacity: 1;
        }

        /* ---------- 悬停时显示的文字（居中，Light 风格） ---------- */
        .thumb .caption-light {
            position: absolute;
            top: 50%;
            left: 0;
            width: 100%;
            transform: translateY(-50%);
            text-align: center;
            color: #fff;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
            z-index: 2;
        }
        .thumb:hover .caption-light {
            opacity: 1;
        }
        .caption-light .entry-date {
            display: block;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
            opacity: 0.8;
        }
        .caption-light .post-tag {
            display: block;
            font-size: 1rem;
            font-weight: 600;
        }

        /* ---------- 隐藏原有的标题和描述（供灯箱使用） ---------- */
        .thumb h2,
        .thumb p {
            display: none;
        }

        /* ---------- 分页导航（预留，暂未使用） ---------- */
        .pagination-nav {
            margin-top: 2rem;
            display: flex;
            justify-content: center;
            gap: 0.5rem;
        }
        .pagination-nav .page-item {
            list-style: none;
        }
        .pagination-nav .page-link {
            display: block;
            padding: 0.5rem 1rem;
            background: #fff;
            border: 1px solid #dee2e6;
            color: #0d6efd;
            text-decoration: none;
            border-radius: 0.25rem;
            transition: background 0.2s;
        }
        .pagination-nav .page-link:hover {
            background: #e9ecef;
        }
        .pagination-nav .page-item.active .page-link {
            background: #0d6efd;
            color: #fff;
            border-color: #0d6efd;
        }
        .pagination-nav .page-item.disabled .page-link {
            color: #6c757d;
            pointer-events: none;
            background: #f8f9fa;
        }

        /* ---------- 无图片时的提示卡片 ---------- */
        .no-images-card {
            background: #fff;
            border-radius: 8px;
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin: 2rem auto;
            max-width: 400px;
        }
        .no-images-card i {
            font-size: 3rem;
            color: #ccc;
            margin-bottom: 1rem;
        }
        .no-images-card p {
            color: #666;
            font-size: 1rem;
        }

        /* ---------- Magnific Popup 淡入动画 ---------- */
        .mfp-fade.mfp-bg {
            opacity: 0;
            transition: all 0.3s ease-out;
        }
        .mfp-fade.mfp-bg.mfp-ready {
            opacity: 0.8;
        }
        .mfp-fade.mfp-bg.mfp-removing {
            opacity: 0;
        }
        .mfp-fade.mfp-wrap .mfp-content {
            opacity: 0;
            transition: all 0.3s ease-out;
        }
        .mfp-fade.mfp-wrap.mfp-ready .mfp-content {
            opacity: 1;
        }
        .mfp-fade.mfp-wrap.mfp-removing .mfp-content {
            opacity: 0;
        }
 /* ---------- 加载更多按钮 ---------- */
 .load-more-btn {
 display: block;
 width: 100%;
 max-width: 300px;
 margin: 2rem auto;
 padding: 0.75rem 1.5rem;
 background: #fff;
 border: 1px solid #dee2e6;
 border-radius: 4px;
 color: #0d6efd;
 font-size: 1rem;
 cursor: pointer;
 transition: all 0.2s;
 }
 .load-more-btn:hover {
 background: #0d6efd;
 color: #fff;
 }
 .load-more-btn:disabled {
 background: #f8f9fa;
 color: #6c757d;
 cursor: not-allowed;
 }
    </style>

    <!-- 预加载首页前4张封面图片，提升用户体验 -->
    <?php if ($pageMode === 'home' && !empty($initialData)): ?>
    <?php for ($i = 0; $i < min(4, count($initialData)); $i++): ?>
    <link rel="preload" as="image" href="<?= htmlspecialchars($initialData[$i]['cover']) ?>">
    <?php endfor; ?>
    <?php endif; ?>
</head>
<body>

<!-- ==================== 导航栏 ==================== -->
<?php $this->need('components/header.php'); ?>

<!-- ==================== 分类信息卡片（仅首页显示） ==================== -->
<?php if ($pageMode === 'home'):
    // 获取当前分类的名称和描述
    $category = $db->fetchRow($db->select('name, description')
        ->from('table.metas')
        ->where('mid = ?', $category_id)
        ->where('type = ?', 'category'));
    if ($category):
        // 获取该分类下的文章总数（从 relationships 表统计）
        $total = $db->fetchObject($db->select('COUNT(*) as num')
            ->from('table.relationships')
            ->where('mid = ?', $category_id))->num;
?>
    <div class="container px-4 px-md-3 mt-3">
        <div class="card category-box border-0 overflow-hidden" style="position: relative; height: 200px;">
            <!-- 随机背景图 -->
            <img src="<?php echo getRandImg(); ?>" alt="分类背景" style="width: 100%; height: 100%; object-fit: cover; position: absolute; top: 0; left: 0; z-index: 1;">
            <!-- 文字遮罩层 -->
            <div class="category-item p-4" style="position: relative; z-index: 2; background: rgba(0,0,0,0.3); height: 100%; display: flex; flex-direction: column; justify-content: flex-end; color: white;">
                <span class="category-name fs-4 fw-bold"><?php echo htmlspecialchars($category['name']); ?> &bull; 共 <?php echo $total; ?> 篇</span>
                <?php if (!empty($category['description'])): ?>
                    <span class="category-desc" title="<?php echo htmlspecialchars($category['description']); ?>"><?php echo htmlspecialchars($category['description']); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php
    endif;
endif;



?>

<!-- ==================== 主内容区域 ==================== -->
<div class="container px-4 px-md-3">

    <!-- 页面标题栏：左侧显示站点名称/相册标题，右侧返回按钮（仅详情页） -->
    <div class="container p-2 d-flex justify-content-between align-items-center">
        <!-- 左侧品牌区 -->
        <a class="navbar-brand fs-5" href="<?= $this->permalink() ?>" title="返回相册">
            <strong><?= $this->options->title ?></strong>
            <small class="text-muted"><?php echo $pageMode === 'home' ? '相册' : '图片详情'; ?></small>
        </a>
        <!-- 右侧返回按钮（仅详情页显示） -->
        <?php if ($pageMode === 'post'): ?>
        <a href="<?= $this->permalink() ?>" class="btn btn-outline-secondary btn-sm">返回相册</a>
        <?php endif; ?>
    </div>

    <!-- 加载指示器（图片加载时显示） -->
    <div id="loading-indicator" class="show">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">加载中...</span>
        </div>
        <span class="ms-2">加载中...</span>
    </div>

    <!-- 图片/文章卡片网格（Masonry 容器） -->
    <div id="main" class="row g-3"></div>

    <!-- 分页导航占位（暂未启用） -->
    <?php if ($pageMode === 'post'): ?>
    <nav id="pagination-nav" class="pagination-nav" aria-label="分页导航" style="display: none;"></nav>
    <?php endif; ?>
</div>

<!-- ==================== 页脚 ==================== -->
<?php $this->need('components/footer.php'); ?>

<!-- ==================== 引入 JavaScript 库 ==================== -->
<!-- jQuery (Magnific Popup 依赖，必须最先加载) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5 JavaScript（含 Popper） -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Masonry 瀑布流布局 -->
<script src="https://unpkg.com/masonry-layout@4/dist/masonry.pkgd.min.js"></script>
<!-- Magnific Popup 灯箱 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/magnific-popup.js/1.1.0/jquery.magnific-popup.min.js"></script>

<!-- ==================== 自定义脚本 ==================== -->
<script>
// 从 PHP 注入的数据
var initialData = <?php echo json_encode($initialData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var pageMode = '<?php echo $pageMode; ?>';
var pagination = <?php echo $pagination ? json_encode($pagination) : 'null'; ?>;

// 图片加载失败的占位图（灰色背景 + 文字）
const PLACEHOLDER_IMAGE = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22400%22%20height%3D%22400%22%20viewBox%3D%220%200%20400%20400%22%3E%3Crect%20width%3D%22400%22%20height%3D%22400%22%20fill%3D%22%23f0f0f0%22%2F%3E%3Ctext%20x%3D%2250%25%22%20y%3D%2250%25%22%20font-family%3D%22Arial%22%20font-size%3D%2220%22%20fill%3D%22%23999%22%20text-anchor%3D%22middle%22%20dy%3D%22.3em%22%3E%E5%9B%BE%E7%89%87%E5%8A%A0%E8%BD%BD%E5%A4%B1%E8%B4%A5%3C%2Ftext%3E%3C%2Fsvg%3E';

let masonryInstance = null; // Masonry 实例（用于切换页面时销毁）

/**
 * 渲染函数：根据页面模式渲染卡片
 */
function render() {
    var container = document.getElementById('main');
    var loading = document.getElementById('loading-indicator');
    if (!container) return;

    loading.classList.add('show');  // 显示加载指示器
    container.innerHTML = '';        // 清空容器

    if (pageMode === 'home') {
        // ---------- 首页模式：渲染文章卡片 ----------
        initialData.forEach(function(item) {
            var col = document.createElement('div');
            col.className = 'col-6 col-md-4 col-lg-3';

            var article = document.createElement('article');
            article.className = 'thumb';

            // 链接到文章详情页，附带 post_id 参数
            var a = document.createElement('a');
            a.className = 'image d-block';
            a.href = '<?= $this->permalink() ?>?post_id=' + item.postId;

            // 创建 img 标签，使用原生懒加载
            var img = document.createElement('img');
            img.src = item.cover;                     // 真实地址
            img.loading = 'lazy';                      // 原生懒加载

            img.alt = item.title ? item.title + ' - 文章封面' : '文章封面';
            img.onload = function() {
                this.classList.add('loaded');
                if (masonryInstance) masonryInstance.layout(); // 加载后重新布局
            };
            img.onerror = function() {
                this.src = PLACEHOLDER_IMAGE;
                this.classList.add('loaded');
                if (masonryInstance) masonryInstance.layout();
            };

            a.appendChild(img);
            article.appendChild(a);

            // 悬停遮罩
            var mask = document.createElement('div');
            mask.className = 'mask';
            article.appendChild(mask);

            // 悬停文字（显示文章标题和图片数量）
            var caption = document.createElement('div');
            caption.className = 'caption-light';
            caption.innerHTML = '<span class="entry-date">' + item.title + '</span>' +
                                '<span class="post-tag">共 ' + item.imageCount + ' 张</span>';
            article.appendChild(caption);

            col.appendChild(article);
            container.appendChild(col);
        });
    } else {
        // ---------- 详情页模式：渲染图片卡片 ----------
        initialData.forEach(function(item) {
            var col = document.createElement('div');
            col.className = 'col-6 col-md-4 col-lg-3';

            var article = document.createElement('article');
            article.className = 'thumb';

            // 链接指向图片本身（原图，供灯箱使用）
            var a = document.createElement('a');
            a.className = 'image d-block';
            a.href = item.url;                     // 原图

            // 创建 img 标签，使用缩略图
            var img = document.createElement('img');
            img.src = item.thumb_url || item.url;   // 优先使用缩略图，无则回退原图
            img.loading = 'lazy';
            img.alt = item.title || item.desc || '图片';
            img.className = 'img-detail';
            img.onload = function() {
                this.classList.add('loaded');
                if (masonryInstance) masonryInstance.layout();
            };
            img.onerror = function() {
                this.src = PLACEHOLDER_IMAGE;
                this.classList.add('loaded');
                if (masonryInstance) masonryInstance.layout();
            };

            a.appendChild(img);
            article.appendChild(a);

            // 悬停遮罩（可根据需要注释以隐藏）
            var mask = document.createElement('div');
            mask.className = 'mask';
            article.appendChild(mask);

            // 悬停文字（已注释，不显示；如需显示可取消注释）
            // var caption = document.createElement('div');
            // caption.className = 'caption-light';
            // caption.innerHTML = '<span class="entry-date">' + (item.desc || '') + '</span>' +
            //                     '<span class="post-tag">' + (item.title || '无标题') + '</span>';
            // article.appendChild(caption);

            // 隐藏元素，供 Magnific Popup 提取标题
            var hiddenH2 = document.createElement('h2');
            hiddenH2.textContent = item.title || '无标题';
            article.appendChild(hiddenH2);

            var hiddenP = document.createElement('p');
            hiddenP.textContent = item.desc || '无描述';
            article.appendChild(hiddenP);

            col.appendChild(article);
            container.appendChild(col);
        });
    }

    // 如果无数据，显示友好提示
    if (initialData.length === 0) {
        loading.classList.remove('show');
        container.innerHTML = '<div class="col-12"><div class="no-images-card"><i class="bi bi-images"></i><p>' +
            (pageMode === 'home' ? '暂无文章，请检查分类ID。' : '该文章暂无图片。') + '</p></div></div>';
        return;
    }

    // 初始化 Masonry 瀑布流
    var grid = document.querySelector('#main');
    if (masonryInstance) masonryInstance.destroy(); // 销毁旧的实例

    masonryInstance = new Masonry(grid, {
        itemSelector: '.col-6',
        percentPosition: true,
        columnWidth: '.col-6'
    });
    console.log('Masonry initialized');

    // 如果是详情页，初始化灯箱
    if (pageMode === 'post') {
        initLightbox();
    }

    // 隐藏加载指示器
 // 渲染加载更多按钮
 if (pagination && pagination.current < pagination.total) {
 var existingBtn = document.getElementById('load-more-btn');
 if (existingBtn) existingBtn.remove();
 
 var remaining = pagination.count - pagination.current * 12;
 var btn = document.createElement('button');
 btn.id = 'load-more-btn';
 btn.className = 'load-more-btn';
 btn.textContent = '加载更多 (剩余 ' + remaining + ' ' + (pageMode === 'home' ? '篇' : '张') + ')';
 btn.onclick = function() {
 this.disabled = true;
 this.textContent = '加载中...';
 loadMore();
 };
 container.parentNode.appendChild(btn);
 }

 loading.classList.remove('show');
}

/**
 * Magnific Popup 灯箱初始化
 */
function initLightbox() {
    if (typeof $ === 'undefined' || !$.fn.magnificPopup) return;

    // 解绑之前的事件（防止重复绑定）
    $('#main').off('click', '.thumb > a.image');

    // 初始化 Magnific Popup
    $('#main').magnificPopup({
        delegate: '.thumb > a.image',          // 触发弹窗的元素
        type: 'image',
        gallery: {
            enabled: true,                      // 开启画廊模式，显示左右箭头
            navigateByImgClick: true,
            preload: [0,2],                     // 预加载前后两张图片
            tPrev: '上一张',
            tNext: '下一张'
        },
        image: {
            titleSrc: function(item) {          // 图片标题来源
                var title = item.el.next('h2').text();
                if (!title) title = item.el.next('p').text();
                return title;
            }
        },
        removalDelay: 300,                      // 关闭动画延迟
        mainClass: 'mfp-fade'                   // 使用淡入淡出动画
    });
}

// 启动渲染
render();
/**
 * 加载更多
 */
function loadMore() {
 var nextPage = pagination.current + 1;
 var url = window.location.pathname + '?page=' + nextPage;
 if (pageMode === 'post') {
 url = '?post_id=' + pagination.postId + '&page=' + nextPage;
 }
 
 fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
 .then(function(res) { return res.text(); })
 .then(function(html) {
 var match = html.match(/var initialData = (\[.*?\]);/s);
 var pageMatch = html.match(/var pagination = (\{.*?\});/s);
 
 if (match && pageMatch) {
 var newData = JSON.parse(match[1]);
 pagination = JSON.parse(pageMatch[1]);
 initialData = initialData.concat(newData);
 appendItems(newData);
 }
 })
 .catch(function(err) {
 console.error('加载失败:', err);
 var btn = document.getElementById('load-more-btn');
 if (btn) {
 btn.disabled = false;
 btn.textContent = '加载失败，点击重试';
 }
 });
}

/**
 * 追加项目到容器
 */
function appendItems(data) {
 var container = document.getElementById('main');
 
 data.forEach(function(item) {
 var col = document.createElement('div');
 col.className = 'col-6 col-md-4 col-lg-3';

 var article = document.createElement('article');
 article.className = 'thumb';

 var a = document.createElement('a');
 a.className = 'image d-block';
 
 if (pageMode === 'home') {
 a.href = '<?= $this->permalink() ?>?post_id=' + item.postId;
 } else {
 a.href = item.url;
 }

 var img = document.createElement('img');
 img.src = pageMode === 'home' ? item.cover : (item.thumb_url || item.url);
 img.loading = 'lazy';
 img.alt = item.title || item.desc || '图片';
 if (pageMode === 'post') img.className = 'img-detail';
 
 img.onload = function() {
 this.classList.add('loaded');
 if (masonryInstance) masonryInstance.layout();
 };
 img.onerror = function() {
 this.src = PLACEHOLDER_IMAGE;
 this.classList.add('loaded');
 if (masonryInstance) masonryInstance.layout();
 };

 a.appendChild(img);
 article.appendChild(a);

 var mask = document.createElement('div');
 mask.className = 'mask';
 article.appendChild(mask);

 if (pageMode === 'home') {
 var caption = document.createElement('div');
 caption.className = 'caption-light';
 caption.innerHTML = '<span class="entry-date">' + item.title + '</span>' +
 '<span class="post-tag">共 ' + item.imageCount + ' 张</span>';
 article.appendChild(caption);
 } else {
 var hiddenH2 = document.createElement('h2');
 hiddenH2.textContent = item.title || '无标题';
 article.appendChild(hiddenH2);

 var hiddenP = document.createElement('p');
 hiddenP.textContent = item.desc || '无描述';
 article.appendChild(hiddenP);
 }

 col.appendChild(article);
 container.appendChild(col);
 });

 // 重新初始化 Masonry
 if (masonryInstance) {
 masonryInstance.reloadItems();
 masonryInstance.layout();
 }
 
 // 重新绑定灯箱（详情页）
 if (pageMode === 'post') {
 initLightbox();
 }
 
 // 更新按钮状态
 var btn = document.getElementById('load-more-btn');
 if (pagination.current >= pagination.total) {
 if (btn) btn.remove();
 } else if (btn) {
 var remaining = pagination.count - pagination.current * 20;
 btn.disabled = false;
 btn.textContent = '加载更多 (剩余 ' + remaining + ' ' + (pageMode === 'home' ? '篇' : '张') + ')';
 }
}
</script>

<!-- Bootstrap Icons（用于无图片提示等） -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</body>

<!-- 调试：当前 perPage = <?php echo $perPage; ?> -->
</html>




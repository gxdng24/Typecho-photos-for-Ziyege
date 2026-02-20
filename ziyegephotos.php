<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 子夜歌双视图相册
 *
 * @版本号:     v1.0.0
 * @作者:       子夜歌
 * @更新日期:    2026-02-17
 * @GitHub:     https://github.com/ziyege/typecho-ziyege-photo
 * @license MIT
 * @说明: 支持文章封面列表与图片详情双视图，集成Masonry瀑布流、Magnific Popup灯箱。
 *          详情页展示单篇文章的所有图片，首页展示指定分类下的文章封面。
 *
 * 功能特性:
 * - 双视图切换（首页/详情页）
 * - 支持行内式和引用式Markdown图片
 * - Masonry瀑布流布局，响应式适配
 * - Magnific Popup灯箱，支持左右导航、标题显示
 * - 完全响应式，基于Bootstrap 5
 * - 图片懒加载（jQuery LazyLoad）
 *
 * @package custom
 */
/**
 * 生成缩略图URL（支持七牛、又拍云、本地附件模拟）
 * @param string $url 原图URL
 * @param int $width 宽度
 * @param int $height 高度
 * @return string 处理后的缩略图URL
 */
function getThumbnailUrl($url, $width = 400, $height = 300) {
    // 如果是空URL，直接返回
    if (empty($url)) return $url;
    // 对URL进行编码（但不影响已有查询参数）
$url = str_replace(' ', '%20', $url);
    // 1. 七牛云存储（需开启图片处理）
    if (strpos($url, '你的七牛域名.com') !== false) {
        return $url . '?imageView2/1/w/' . $width . '/h/' . $height;
    }
    
    // 2. 又拍云存储
    if (strpos($url, '你的又拍云域名.com') !== false) {
        return $url . '!/both/' . $width . 'x' . $height;
    }
    
    // 3. 阿里云OSS
    if (strpos($url, '你的OSS域名.com') !== false) {
        return $url . '?x-oss-process=image/resize,m_fixed,w_' . $width . ',h_' . $height;
    }
    
    // 4. 本地附件（假设上传目录为 /usr/uploads/）
    if (strpos($url, '/usr/uploads/') !== false) {
        // 如果没有云存储，暂时无法生成缩略图，返回原图
        // 建议升级方案：使用七牛镜像存储或安装缩略图插件
        return $url;
    }
    // 5. 缤纷云存储
    if (strpos($url, 'cdn.ziyege.com') !== false) {
        // 选项A：强制裁剪模式（封面图统一尺寸）
        // 同时指定w和h，系统自动按mode=crop处理，居中裁剪
        return $url . '?w=' . $width . '&h=' . $height;
        
        // 选项B：等比缩略模式（保留完整图片，可能有留白）
        // 如需使用，请注释上面一行，取消下面一行的注释
        // return $url . '?w=' . $width . '&h=' . $height . '&mode=clip';
        
        // 选项C：如需适配高分辨率屏幕（如Retina），可添加dpr参数
        // return $url . '?w=' . $width . '&h=' . $height . '&dpr=2';
    }
    
    // 6. 其他外链图片，保持原图
    return $url;
}
// ==================== 初始化参数 ====================
// 获取URL参数 post_id，决定当前是首页还是详情页
$post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
$pageMode = $post_id ? 'post' : 'home'; // 当前页面模式：home 或 post

// ==================== 辅助函数：从 Markdown 提取图片 ====================
/**
 * 从 Markdown 文本中提取所有图片（支持引用式和行内式）
 * @param string $text 文章内容（Markdown格式）
 * @return array 图片数组，每个元素包含 ['title' => alt文本, 'url' => 图片URL]
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

// ==================== 获取数据库连接 ====================
$db = Typecho_Db::get();

// ==================== 根据页面模式获取数据 ====================
$initialData = []; // 存储将要传递给前端的数据（JSON格式）
$pageTitle   = ''; // 页面标题

if ($pageMode === 'home') {
    // ---------- 首页模式：获取指定分类下的所有文章 ----------
    $category_id = 3; // ⚠️ 请改为你实际的分类ID

    $posts = $db->fetchAll($db->select('table.contents.cid, table.contents.title, table.contents.text')
        ->from('table.relationships')
        ->join('table.contents', 'table.contents.cid = table.relationships.cid', Typecho_Db::INNER_JOIN)
        ->where('table.relationships.mid = ?', $category_id)
        ->where('table.contents.type = ?', 'post')
        ->where('table.contents.status = ?', 'publish')
        ->order('table.contents.created', Typecho_Db::SORT_DESC));

    foreach ($posts as $post) {
        $articleImages = extractImagesFromMarkdown($post['text']);
        if (count($articleImages) > 0) {
            // 每篇文章生成一条数据，供前端渲染卡片
            $initialData[] = [
    'type'       => 'article',
    'title'      => $post['title'],
    'cover'      => getThumbnailUrl($articleImages[0]['url'], 400, 300),  // 生成400x300缩略图
    'imageCount' => count($articleImages),
    'postId'     => $post['cid']
];
        }
    }
    $pageTitle = '相册 - ' . $this->options->title;    // 页面标题

} else {
    // ---------- 详情模式：获取单篇文章的所有图片 ----------
    $post = $db->fetchRow($db->select('title, text')
        ->from('table.contents')
        ->where('cid = ?', $post_id)
        ->where('type = ?', 'post')
        ->where('status = ?', 'publish'));

    if ($post) {
        $images = extractImagesFromMarkdown($post['text']);
        foreach ($images as $img) {
            // 每张图片生成一条数据
            $initialData[] = [
                'type'  => 'image',                  // 数据类型：图片
                'title' => $img['title'],            // 图片标题（alt）
                'desc'  => $post['title'],           // 所属文章标题（用作描述）
                'url'   => $img['url']                // 图片URL
            ];
        }
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

    <!-- Bootstrap 5 核心CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Magnific Popup 灯箱CSS -->
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
            margin-bottom: 1.5rem;
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
            display: block;                  /* 去除图片下方多余间隙 */
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

        /* ---------- 悬停时显示的文字（居中，Light风格） ---------- */
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

        /* ---------- 响应式左右边距 ---------- */
        .container-fluid.custom-wide {
            padding-left: 50px;
            padding-right: 50px;
        }
        @media (max-width: 768px) {
            .container-fluid.custom-wide {
                padding-left: 20px;
                padding-right: 20px;
            }
        }
        @media (max-width: 576px) {
            .container-fluid.custom-wide {
                padding-left: 12px;
                padding-right: 12px;
            }
        }

        /* ---------- 分页导航（详情页可能需要） ---------- */
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
    </style>
    <?php if ($pageMode === 'home' && !empty($initialData)): ?>
    <?php for ($i = 0; $i < min(4, count($initialData)); $i++): ?>
    <link rel="preload" as="image" href="<?= htmlspecialchars($initialData[$i]['cover']) ?>">
    <?php endfor; ?>
<?php endif; ?>
</head>
<body>
<!-- ==================== 导航栏 ==================== -->
<nav class="navbar navbar-light bg-white shadow-sm mb-4">
    <div class="container-fluid">
        <!-- 左侧品牌区：点击返回首页，根据模式显示不同文字 -->
        <a class="navbar-brand fs-4" href="<?= $this->permalink() ?>" title="返回相册">
            <strong><?= $this->options->title ?></strong>
            <small class="text-muted"><?php echo $pageMode === 'home' ? '相册' : '图片详情'; ?></small>
        </a>
        <!-- 详情页时显示返回相册按钮 -->
        <?php if ($pageMode === 'post'): ?>
        <a href="<?= $this->permalink() ?>" class="btn btn-outline-secondary btn-sm">返回相册</a>
        <?php endif; ?>
    </div>
</nav>

<!-- ==================== 主内容区域 ==================== -->
<div class="container-fluid custom-wide px-4 px-md-5">
    <!-- 加载指示器（图片加载时显示） -->
    <div id="loading-indicator" class="show">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">加载中...</span>
        </div>
        <span class="ms-2">加载中...</span>
    </div>

    <!-- 图片/文章卡片网格（Masonry容器） -->
    <div id="main" class="row g-4"></div>

    <!-- 分页导航占位（仅详情页可能需要，目前为空） -->
    <?php if ($pageMode === 'post'): ?>
    <nav id="pagination-nav" class="pagination-nav" aria-label="分页导航" style="display: none;"></nav>
    <?php endif; ?>
</div>

<!-- ==================== 页脚 ==================== -->
<footer class="bg-white mt-5 py-4 border-top">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <!-- 左侧：相册名称，点击返回首页 -->
            <a href="<?= $this->options->siteUrl ?>" title="返回主页" class="fw-bold fs-4 text-decoration-none text-dark">
    <?= $this->options->title ?> 碧落山水间，品清韵悠扬
</a>
            <!-- 右侧：版权指示 -->
            <div class="text-muted small">
                &copy; <?php echo date('Y'); ?> 
                <a href="<?= $this->options->siteUrl ?>" class="text-decoration-none text-muted">
                  
                </a> 
                · Powered by <a href="http://typecho.org" target="_blank" rel="nofollow">Typecho</a> · Designed by <a href="https://blog.ziyege.com" target="_blank">ziyege.com</a>
            </div>
        </div>
    </div>
</footer>

<!-- ==================== 引入 JavaScript 库 ==================== -->
<!-- Bootstrap 5 JavaScript（含 Popper） -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery (Magnific Popup 依赖) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Masonry 瀑布流布局 -->
<script src="https://unpkg.com/masonry-layout@4/dist/masonry.pkgd.min.js"></script>
<!-- imagesLoaded（确保图片加载后再布局） -->
<script src="https://unpkg.com/imagesloaded@5/imagesloaded.pkgd.min.js"></script>
<!-- jQuery LazyLoad 懒加载插件 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.lazyload/1.9.1/jquery.lazyload.min.js"></script>
<!-- Magnific Popup 灯箱 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/magnific-popup.js/1.1.0/jquery.magnific-popup.min.js"></script>

<!-- ==================== 自定义脚本 ==================== -->
<script>
// 从 PHP 注入的数据
var initialData = <?php echo json_encode($initialData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var pageMode = '<?php echo $pageMode; ?>'; // 'home' 或 'post'

// 透明占位图（极小的 base64 图片，用于懒加载初始 src）
const BLANK_IMAGE = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
// 图片加载失败的占位图（灰色背景 + 文字）
const PLACEHOLDER_IMAGE = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22400%22%20height%3D%22400%22%20viewBox%3D%220%200%20400%20400%22%3E%3Crect%20width%3D%22400%22%20height%3D%22400%22%20fill%3D%22%23f0f0f0%22%2F%3E%3Ctext%20x%3D%2250%25%22%20y%3D%2250%25%22%20font-family%3D%22Arial%22%20font-size%3D%2220%22%20fill%3D%22%23999%22%20text-anchor%3D%22middle%22%20dy%3D%22.3em%22%3E%E5%9B%BE%E7%89%87%E5%8A%A0%E8%BD%BD%E5%A4%B1%E8%B4%A5%3C%2Ftext%3E%3C%2Fsvg%3E';

let masonryInstance = null; // Masonry 实例（用于切换页面时销毁）

// ==================== 渲染函数（根据页面模式渲染卡片） ====================
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

            // 创建 img 标签，使用懒加载
            var img = document.createElement('img');
            img.src = BLANK_IMAGE;                     // 初始占位图
            img.setAttribute('data-original', item.cover); // 真实地址
            img.alt = item.title || '文章封面';
            img.className = 'lazy';                     // 供插件识别
            img.onerror = function() {
                this.src = PLACEHOLDER_IMAGE;
                if (masonryInstance) masonryInstance.layout(); // 错误后重新布局
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

            // 链接指向图片本身（供灯箱使用）
            var a = document.createElement('a');
            a.className = 'image d-block';
            a.href = item.url;

            // 创建 img 标签，使用懒加载
            var img = document.createElement('img');
            img.src = BLANK_IMAGE;                      // 初始占位图
            img.setAttribute('data-original', item.url); // 真实地址
            img.alt = item.title || '图片';
            img.className = 'lazy';                      // 供插件识别
            img.onerror = function() {
                this.src = PLACEHOLDER_IMAGE;
                if (masonryInstance) masonryInstance.layout(); // 错误后重新布局
            };

            a.appendChild(img);
            article.appendChild(a);

            // 悬停遮罩
            var mask = document.createElement('div');
            mask.className = 'mask';
            article.appendChild(mask);

            // 悬停文字（显示文章标题和图片标题）
            var caption = document.createElement('div');
            caption.className = 'caption-light';
            caption.innerHTML = '<span class="entry-date">' + (item.desc || '') + '</span>' +
                                '<span class="post-tag">' + (item.title || '无标题') + '</span>';
            article.appendChild(caption);

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

    // ---------- 初始化 Masonry 瀑布流 ----------
    var grid = document.querySelector('#main');
    imagesLoaded(grid, function() {
        // 销毁旧的 Masonry 实例（防止内存泄漏）
        if (masonryInstance) masonryInstance.destroy();

        // 创建新的 Masonry 实例
        masonryInstance = new Masonry(grid, {
            itemSelector: '.col-6',
            percentPosition: true,
            columnWidth: '.col-6'
        });
        console.log('Masonry initialized');

        // 初始化懒加载
        $("img.lazy").lazyload({
            effect: "fadeIn",                // 淡入效果
            threshold: 200,                   // 提前 200px 加载
            load: function() {
                // 图片加载成功后重新布局 Masonry
                if (masonryInstance) masonryInstance.layout();
                console.log('Lazy image loaded, masonry relayout');
            }
        });

        // 如果是详情页，初始化灯箱
        if (pageMode === 'post') {
            initLightbox();
        }

        // 隐藏加载指示器
        loading.classList.remove('show');
    });
}

// ==================== Magnific Popup 灯箱初始化 ====================
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

// ==================== 启动渲染 ====================
render();
</script>

<!-- Bootstrap Icons（用于无图片提示等） -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</body>
</html>

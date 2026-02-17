<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 子夜歌双视图相册
 *
 * 版本号:     v1.0.0
 * 作者:       子夜歌
 * 更新日期:    2026-02-17
 * GitHub:     https://github.com/ziyege/typecho-ziyege-photo
 * 说明: 支持文章封面列表与图片详情双视图，集成Masonry瀑布流、Uncover动画、Magnific Popup灯箱。
 *          详情页展示单篇文章的所有图片，首页展示指定分类下的文章封面。
 *
 * 功能特性:
 * - 双视图切换（首页/详情页）
 * - 支持行内式和引用式Markdown图片
 * - Masonry瀑布流布局，响应式适配
 * - Uncover切片动画（进入视口时触发）
 * - Magnific Popup灯箱，支持左右导航、标题显示
 * - 页脚控制台（关于按钮弹出全宽面板）
 * - 完全响应式，基于Bootstrap 5
 *
 * @package custom
 */

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
                'type'       => 'article',               // 数据类型：文章卡片
                'title'      => $post['title'],          // 文章标题
                'cover'      => $articleImages[0]['url'],// 封面图（第一张）
                'imageCount' => count($articleImages),   // 该文章图片总数
                'postId'     => $post['cid']             // 文章ID，用于生成详情页链接
            ];
        }
    }
    $pageTitle = '相册 - ' . $this->options->title();    // 页面标题

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
        header('Location: ' . $this->options->siteUrl . $this->request->getRequestUri());
        exit;
    }
}
?>
<!DOCTYPE HTML>
<html lang="zh-CN">
<head>
    <title><?php echo $pageTitle; ?></title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />

    <!-- Bootstrap 5 核心CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Uncover 动画专用CSS -->
    <link rel="stylesheet" href="<?php $this->options->themeUrl('ziyege/uncover.css'); ?>">

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
        }
        .thumb:hover {
            transform: scale(1.02);
        }

        /* ---------- 图片背景区（用于 Uncover 动画） ---------- */
        .scroll-img {
            width: 100%;
            aspect-ratio: 1 / 1;             /* 正方形 */
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-color: #f0f0f0;        /* 加载中的占位色 */
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
</head>
<body>

<!-- ==================== 导航栏 ==================== -->
<nav class="navbar navbar-light bg-white shadow-sm mb-4">
    <div class="container-fluid">
        <!-- 左侧品牌区：点击返回首页，根据模式显示不同文字 -->
        <a class="navbar-brand fs-4" href="<?php $this->permalink(); ?>">
            <strong><?php $this->options->title(); ?></strong>
            <small class="text-muted"><?php echo $pageMode === 'home' ? '相册' : '图片详情'; ?></small>
        </a>
        <!-- 详情页时显示返回相册按钮 -->
        <?php if ($pageMode === 'post'): ?>
        <a href="<?php $this->permalink(); ?>" class="btn btn-outline-secondary btn-sm">返回相册</a>
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
            <a href="<?php $this->permalink(); ?>" class="fw-bold fs-4 text-decoration-none text-dark">
                <?php $this->options->title(); ?> 相册
            </a>
            <!-- 右侧：关于按钮，触发 Offcanvas 面板 -->
            <button class="btn btn-outline-secondary btn-sm fs-6 py-2 px-3" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#footerOffcanvas" aria-controls="footerOffcanvas">
                关于
            </button>
        </div>
    </div>
</footer>

<!-- ==================== Offcanvas 控制台面板 ==================== -->
<div class="offcanvas offcanvas-bottom" tabindex="-1" id="footerOffcanvas" aria-labelledby="offcanvasLabel"
     style="height: auto; min-height: 300px;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="offcanvasLabel">控制台</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <p>本系统共有 <span id="count_CN">0</span> 张图片，每页显示 12 张，集成 Masonry 瀑布流与 Uncover 动画。</p>
        <p class="text-muted small mb-0">
            &copy; <?php echo date('Y'); ?> <a href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></a> · Powered by ZDSR
        </p>
    </div>
</div>

<!-- ==================== 引入 JavaScript 库 ==================== -->
<!-- Bootstrap 5 JavaScript（含 Popper） -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery (Magnific Popup 依赖) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Masonry 瀑布流布局 -->
<script src="https://unpkg.com/masonry-layout@4/dist/masonry.pkgd.min.js"></script>
<!-- imagesLoaded（确保图片加载后再布局） -->
<script src="https://unpkg.com/imagesloaded@5/imagesloaded.pkgd.min.js"></script>
<!-- Anime.js（Uncover 动画引擎） -->
<script src="<?php $this->options->themeUrl('ziyege/anime.min.js'); ?>"></script>
<!-- Uncover.js（切片动画库） -->
<script src="<?php $this->options->themeUrl('ziyege/uncover.js'); ?>"></script>
<!-- Magnific Popup 灯箱 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/magnific-popup.js/1.1.0/jquery.magnific-popup.min.js"></script>

<!-- ==================== 自定义脚本 ==================== -->
<script>
// 从 PHP 注入的数据
var initialData = <?php echo json_encode($initialData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var pageMode = '<?php echo $pageMode; ?>'; // 'home' 或 'post'

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
            a.href = '<?php $this->permalink(); ?>?post_id=' + item.postId;

            // 背景图容器（用于 Uncover 动画）
            var bgDiv = document.createElement('div');
            bgDiv.className = 'scroll-img';
            bgDiv.style.backgroundImage = 'url(' + item.cover + ')';
            bgDiv.onerror = function() {
                this.style.backgroundImage = 'url(' + PLACEHOLDER_IMAGE + ')';
            };

            a.appendChild(bgDiv);
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

            var bgDiv = document.createElement('div');
            bgDiv.className = 'scroll-img';
            bgDiv.style.backgroundImage = 'url(' + item.url + ')';
            bgDiv.onerror = function() {
                this.style.backgroundImage = 'url(' + PLACEHOLDER_IMAGE + ')';
            };

            a.appendChild(bgDiv);
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

        // 初始化 Uncover 动画
        initUncover();

        // 如果是详情页，初始化灯箱
        if (pageMode === 'post') {
            initLightbox();
        }

        // 隐藏加载指示器
        loading.classList.remove('show');
    });
}

// ==================== Uncover 动画初始化 ====================
function initUncover() {
    if (typeof Uncover === 'undefined') return;

    var items = Array.from(document.querySelectorAll('.scroll-img'));
    if (items.length === 0) return;

    // 根据屏幕宽度决定切片数量（手机 3，桌面 4）
    const isMobile = window.innerWidth < 768;
    const slicesTotal = isMobile ? 3 : 4;

    // 两种配置，奇偶交替，增加动感
    const uncoverOpts = [
        { slicesTotal: slicesTotal, slicesColor: '#fff', orientation: 'horizontal', slicesOrigin: { show: 'right', hide: 'left' } },
        { slicesTotal: slicesTotal, slicesColor: '#fff', orientation: 'horizontal', slicesOrigin: { show: 'left', hide: 'right' } }
    ];

    var uncoverArr = items.map(function(el, index) {
        return new Uncover(el, uncoverOpts[index % 2]);
    });

    // 动画参数（与 Light 模板一致）
    var animationSettings = {
        show: { slices: { duration: 600, delay: (_, i, t) => (t - i - 1) * 100, easing: 'easeInOutCirc' } },
        hide: { slices: { duration: 600, delay: (_, i, t) => (t - i - 1) * 100, easing: 'easeInOutCirc' } }
    };

    // 使用 IntersectionObserver 监听元素进入/离开视口
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            var idx = items.indexOf(entry.target);
            if (idx === -1) return;
            if (entry.intersectionRatio > 0.3) {
                uncoverArr[idx].show(true, animationSettings.show);
            } else {
                uncoverArr[idx].hide(true, animationSettings.hide);
            }
        });
    }, { threshold: [0, 0.3, 0.5, 1] });

    items.forEach(function(item) {
        observer.observe(item);
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

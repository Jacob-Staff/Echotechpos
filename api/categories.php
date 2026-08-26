<?php
/**
 * PHARMANOVA Online Store - Categories
 * Uses store_header.php for branch, tenant, logo and navigation context.
 */
require_once __DIR__ . '/store_header.php';

$active_section = trim($_GET['section'] ?? '');
$active_label = '';
foreach ($nav as $label => $menu) {
    if (($menu['section'] ?? '') === $active_section) {
        $active_label = $label;
        break;
    }
}
?>

<style>
    .categories-page{background:#f7f9fb;min-height:calc(100vh - 180px);padding:24px 0 55px;}
    .categories-hero{background:#fff;border:1px solid #e5eaee;border-radius:14px;padding:22px 24px;margin-bottom:20px;box-shadow:0 2px 8px rgba(20,40,60,.04);}
    .categories-hero h1{margin:0;color:#173b59;font-size:25px;font-weight:800;}
    .categories-hero p{margin:7px 0 0;color:#71808d;font-size:13px;}
    .category-layout{display:grid;grid-template-columns:235px minmax(0,1fr);gap:16px;align-items:start;}
    .category-sidebar{background:#fff;border:1px solid #e3e9ed;border-radius:12px;overflow:hidden;position:sticky;top:12px;}
    .category-sidebar-title{padding:15px 16px;border-bottom:1px solid #edf0f2;font-size:13px;font-weight:800;color:#34495a;}
    .category-sidebar a{display:flex;align-items:center;gap:9px;padding:12px 14px;text-decoration:none;color:#657583;font-size:12px;font-weight:600;border-left:3px solid transparent;border-bottom:1px solid #f2f4f5;}
    .category-sidebar a:hover,.category-sidebar a.active{background:#f3f8fe;color:#1769d1;border-left-color:#1769d1;}
    .category-sidebar i{font-size:18px;width:22px;text-align:center;}
    .category-main{min-width:0;}
    .category-section{background:#fff;border:1px solid #e3e9ed;border-radius:12px;margin-bottom:16px;overflow:hidden;box-shadow:0 2px 8px rgba(20,40,60,.03);scroll-margin-top:15px;}
    .category-section-head{display:flex;align-items:center;justify-content:space-between;padding:15px 18px;border-bottom:1px solid #edf0f2;}
    .category-section-head h2{margin:0;color:#243746;font-size:17px;font-weight:800;}
    .category-section-head a{font-size:11px;color:#1769d1;font-weight:800;text-decoration:none;}
    .category-groups{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0;}
    .category-group{padding:16px 18px;border-right:1px solid #f0f2f4;border-bottom:1px solid #f0f2f4;}
    .category-group h3{font-size:12px;margin:0 0 8px;color:#3d5363;font-weight:800;}
    .category-group h3 a{color:inherit;text-decoration:none;}
    .category-group ul{list-style:none;padding:0;margin:0;}
    .category-group li{margin:0;}
    .category-group li a{display:block;color:#71808d;text-decoration:none;font-size:11px;padding:4px 0;line-height:1.25;}
    .category-group li a:hover{color:#1769d1;}
    .category-empty{padding:20px;color:#71808d;font-size:12px;}
    .mobile-category-page{display:none;}
    .mobile-cat-card{background:#fff;border:1px solid #e3e9ed;border-radius:10px;margin-bottom:9px;overflow:hidden;}
    .mobile-cat-head{width:100%;border:0;background:#fff;padding:14px;display:flex;align-items:center;gap:10px;text-align:left;color:#304758;font-weight:800;font-size:13px;}
    .mobile-cat-head i:first-child{width:24px;color:#1769d1;font-size:19px;}
    .mobile-cat-head span{flex:1;}
    .mobile-cat-body{display:none;background:#f8fafb;border-top:1px solid #edf0f2;padding:4px 0 9px;}
    .mobile-cat-card.open .mobile-cat-body{display:block;}
    .mobile-group{border-bottom:1px solid #edf0f2;}
    .mobile-group:last-child{border-bottom:0;}
    .mobile-group button{width:100%;border:0;background:transparent;padding:11px 16px 9px 48px;display:flex;justify-content:space-between;text-align:left;color:#526575;font-size:12px;font-weight:700;}
    .mobile-group-links{display:none;padding:0 18px 8px 64px;}
    .mobile-group.open .mobile-group-links{display:block;}
    .mobile-group-links a{display:block;color:#71808d;text-decoration:none;font-size:11px;padding:6px 0;}
    @media(max-width:767.98px){
        .categories-page{padding:12px 10px 25px;min-height:calc(100vh - 130px);}
        .categories-hero{padding:16px;margin-bottom:11px;border-radius:10px;}
        .categories-hero h1{font-size:20px;}
        .categories-hero p{font-size:11px;}
        .category-layout{display:none;}
        .mobile-category-page{display:block;}
    }
</style>

<main class="categories-page">
    <div class="container-fluid px-2 px-md-4">
        <section class="categories-hero">
            <h1>Shop by Category</h1>
            <p>Browse medicines, personal care, health products and more from <?php echo htmlspecialchars($pharmacy_name); ?> — <?php echo htmlspecialchars($branch_name); ?>.</p>
        </section>

        <div class="category-layout">
            <aside class="category-sidebar">
                <div class="category-sidebar-title">All Categories</div>
                <?php foreach ($nav as $label => $menu): ?>
                    <a class="<?php echo $active_label === $label ? 'active' : ''; ?>" href="#cat-<?php echo htmlspecialchars($menu['section']); ?>">
                        <i class="mdi <?php echo htmlspecialchars($menu['icon']); ?>"></i>
                        <span><?php echo htmlspecialchars($label); ?></span>
                    </a>
                <?php endforeach; ?>
            </aside>

            <section class="category-main">
                <?php foreach ($nav as $label => $menu): ?>
                    <article class="category-section" id="cat-<?php echo htmlspecialchars($menu['section']); ?>">
                        <div class="category-section-head">
                            <h2><i class="mdi <?php echo htmlspecialchars($menu['icon']); ?> me-1" style="color:#1769d1"></i><?php echo htmlspecialchars($label); ?></h2>
                            <a href="<?php echo htmlspecialchars($make_section_url($menu['section'], $branch_id)); ?>">VIEW PRODUCTS</a>
                        </div>
                        <?php if (!empty($menu['groups'])): ?>
                            <div class="category-groups">
                                <?php foreach ($menu['groups'] as $group => $links): ?>
                                    <div class="category-group">
                                        <h3><a href="<?php echo htmlspecialchars($make_search_url($group, $branch_id)); ?>"><?php echo htmlspecialchars($group); ?></a></h3>
                                        <ul>
                                            <?php foreach ($links as $sub): ?>
                                                <li><a href="<?php echo htmlspecialchars($make_search_url($sub, $branch_id)); ?>"><?php echo htmlspecialchars($sub); ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="category-empty">Browse all medicines available at this branch.</div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>

        <section class="mobile-category-page">
            <?php foreach ($nav as $label => $menu): ?>
                <article class="mobile-cat-card <?php echo $active_label === $label ? 'open' : ''; ?>">
                    <button type="button" class="mobile-cat-head">
                        <i class="mdi <?php echo htmlspecialchars($menu['icon']); ?>"></i>
                        <span><?php echo htmlspecialchars($label); ?></span>
                        <i class="mdi mdi-chevron-down mobile-cat-chevron"></i>
                    </button>
                    <div class="mobile-cat-body">
                        <?php if (!empty($menu['groups'])): ?>
                            <?php foreach ($menu['groups'] as $group => $links): ?>
                                <div class="mobile-group">
                                    <button type="button">
                                        <span><?php echo htmlspecialchars($group); ?></span>
                                        <i class="mdi mdi-chevron-down"></i>
                                    </button>
                                    <div class="mobile-group-links">
                                        <a href="<?php echo htmlspecialchars($make_search_url($group, $branch_id)); ?>">View <?php echo htmlspecialchars($group); ?></a>
                                        <?php foreach ($links as $sub): ?>
                                            <a href="<?php echo htmlspecialchars($make_search_url($sub, $branch_id)); ?>"><?php echo htmlspecialchars($sub); ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($make_section_url($menu['section'], $branch_id)); ?>" style="display:block;padding:12px 18px;color:#1769d1;text-decoration:none;font-size:12px;font-weight:700;">View Medicines</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
</main>

<script>
(function(){
    document.querySelectorAll('.mobile-cat-head').forEach(function(btn){
        btn.addEventListener('click', function(){
            const card = btn.closest('.mobile-cat-card');
            const wasOpen = card.classList.contains('open');
            document.querySelectorAll('.mobile-cat-card.open').forEach(function(other){
                if(other !== card) other.classList.remove('open');
            });
            card.classList.toggle('open', !wasOpen);
        });
    });
    document.querySelectorAll('.mobile-group > button').forEach(function(btn){
        btn.addEventListener('click', function(){
            const group = btn.closest('.mobile-group');
            const wasOpen = group.classList.contains('open');
            const body = group.closest('.mobile-cat-body');
            if(body) body.querySelectorAll('.mobile-group.open').forEach(function(other){
                if(other !== group) other.classList.remove('open');
            });
            group.classList.toggle('open', !wasOpen);
            const icon = btn.querySelector('i');
            if(icon) icon.className = 'mdi ' + (group.classList.contains('open') ? 'mdi-chevron-up' : 'mdi-chevron-down');
        });
    });
})();
</script>

<?php
$footer_path = __DIR__ . "/includes/footer.php";
if (file_exists($footer_path)) require $footer_path;
?>

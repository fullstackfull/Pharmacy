{{-- Storefront renderer for the visual Theme Builder's home sections (Phase 1 theme system).
     Renders the published version's sections when a merchant has composed any; when sectionsFor()
     returns null (no published theme, or no home sections) this outputs nothing and the theme's own
     hardcoded home is shown unchanged — the compatibility shim the builder was designed around.

     Every read goes through SectionDataResolver: a view that queries is a view that can 500 the
     shop, and this file already did once. Styling is a self-contained glossy "Minimal Luxury"
     system scoped to .ml-sections, so it upgrades these sections without touching the legacy
     storefront blades. --}}
@php
    $__sections = app(\App\Services\Theme\StorefrontThemeRenderer::class)->sectionsFor('home');
    $__data = app(\App\Services\Theme\SectionDataResolver::class);
    $__ready = app(\App\Services\Theme\SectionReadiness::class);
    $__placeholder = dynamicAsset(path: 'public/assets/front-end/img/image-place-holder.png');
    // Types this file can draw. A section whose type has no renderer here is skipped entirely
    // rather than emitting an empty padded <section>, which reads on the page as a broken gap.
    $__renderable = ['hero_banner', 'category_grid', 'product_slider', 'brand_slider', 'promotional_banner',
        'split_banner', 'banner_mosaic', 'banner_strip', 'store_banner', 'usp_strip', 'newsletter',
        'custom_html', 'spacer', 'flash_deal', 'testimonials', 'faq', 'category_showcase',
        'vendor_slider', 'vendor_showcase',
        'deal_of_the_day', 'featured_deal', 'clearance_sale', 'coupon_strip', 'stats_bar', 'bundle',
        'interest_tiles', 'stories', 'blog_posts', 'branches', 'shipping_cutoff', 'before_after',
        'product_tabs', 'brand_showcase', 'trending_searches', 'recently_viewed', 'app_download', 'price_tiles'];
@endphp

@if (!empty($__sections))
<style>
    @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Tajawal:wght@700;800;900&display=swap');

    /* ---- Glossy design language (violet -> blue gradient, tinted sections) -------------- */
    .ml-sections{
        --ml-primary:var(--web-primary,#7B3FE4);
        --ml-secondary:var(--web-secondary,#26ABF2);
        --ml-grad:linear-gradient(140deg,var(--ml-primary),var(--ml-secondary));
        --ml-dark:linear-gradient(155deg,#1A0940 0%,#2C1160 38%,#4B1B8F 72%,#6B2AB8 100%);
        --ml-ink:#14082E; --ml-ink2:#2E2350; --ml-muted:#8B84A3;
        --ml-line:#E4DFF2; --ml-sand:#F7F5FD; --ml-paper:#fff;
        --ml-gold:var(--ml-primary); --ml-gold-soft:#cdb8f0; --ml-gold-dark:var(--ml-primary);
        --ml-serif:"Tajawal","IBM Plex Sans Arabic",system-ui,sans-serif;
        /* Layered shadows read as depth rather than a grey blur, and the spring curve is what
           makes a hover feel alive instead of mechanical. */
        --ml-shadow:0 2px 6px rgba(20,8,46,.04), 0 12px 32px -12px rgba(20,8,46,.16);
        --ml-shadow-lg:0 4px 12px rgba(20,8,46,.05), 0 28px 60px -20px rgba(20,8,46,.26);
        --ml-glow:0 10px 30px -12px rgba(123,63,228,.55);
        --ml-ease:cubic-bezier(.22,.61,.36,1);
        --ml-spring:cubic-bezier(.34,1.4,.64,1);
        color:var(--ml-ink); position:relative;
        font-family:"IBM Plex Sans Arabic",system-ui,sans-serif;
    }
    .ml-sections *{ box-sizing:border-box; }
    /* The legacy storefront sets a flat colour on every h1..h6, and an element rule beats
       inheritance — which turned white-on-image headings dark. Opt these back into inheriting,
       so a slide's text colour actually applies. */
    .ml-sections h1,.ml-sections h2,.ml-sections h3,.ml-sections h4,.ml-sections h5,.ml-sections h6{ color:inherit; }
    .ml-sections .tbs{ position:relative; overflow:hidden; }
    .ml-sections .tbs > .container,.ml-sections .tbs > .container-fluid{ max-width:1380px; position:relative; z-index:1; }
    .ml-sections .tbs:nth-child(even){ background:var(--ml-sand); }

    /* ---- builder-driven layout: columns, gap and content alignment --------------------- */
    /* One grid honours the section's "columns" setting (and its tablet/mobile overrides,
       which arrive as --tb-cols in a per-section media rule). Mobile stays at two columns
       unless the merchant says otherwise, so a 6-column desktop row is still readable. */
    .ml-grid{ display:grid; gap:var(--tb-gap,20px); grid-template-columns:repeat(var(--tb-cols,4),minmax(0,1fr)); }
    @media (max-width:767.98px){ .ml-grid{ grid-template-columns:repeat(var(--tb-cols-sm,2),minmax(0,1fr)); } }
    .tbs-category_grid .ml-grid{ --tb-cols-sm:3; justify-items:center; }

    .tbs-align-center{ text-align:center; }
    .tbs-align-center .ml-sec-head{ flex-direction:column; align-items:center; text-align:center; }
    .tbs-align-center .ml-sec-head .ml-rule{ display:block; margin-inline:auto; }
    .tbs-align-center .ml-usp,.tbs-align-center .ml-card__body{ justify-content:center; text-align:center; }
    .tbs-align-end{ text-align:end; }
    .tbs-align-end .ml-sec-head{ flex-direction:column; align-items:flex-end; text-align:end; }

    /* ---- scroll reveal ---------------------------------------------------------------- */
    .ml-reveal{ opacity:0; transform:translateY(26px) scale(.985);
        transition:opacity .8s var(--ml-ease), transform .8s var(--ml-spring); }
    .ml-reveal.is-in{ opacity:1; transform:none; }
    .ml-reveal[data-delay="1"]{ transition-delay:.07s } .ml-reveal[data-delay="2"]{ transition-delay:.14s }
    .ml-reveal[data-delay="3"]{ transition-delay:.21s } .ml-reveal[data-delay="4"]{ transition-delay:.28s }
    .ml-reveal[data-delay="5"]{ transition-delay:.35s } .ml-reveal[data-delay="6"]{ transition-delay:.42s }

    /* ---- headings --------------------------------------------------------------------- */
    .ml-sec-head{ display:flex; flex-wrap:wrap; align-items:end; justify-content:space-between; gap:12px 20px; margin-bottom:18px; text-align:start; }
    .ml-sec-head--center{ display:block; text-align:center; margin-bottom:2rem; }
    .ml-eyebrow{ display:block; font-size:.66rem; letter-spacing:.14em; text-transform:uppercase;
        color:var(--ml-secondary); font-weight:700; margin-bottom:.25rem; }
    .ml-sec-head h2{ font-family:var(--ml-serif); font-weight:800; font-size:clamp(1.35rem,2.4vw,1.7rem);
        margin:0; line-height:1.2; color:var(--ml-ink); }
    .ml-sec-head .ml-rule{ display:none; }
    .ml-sec-head--center .ml-rule{ display:block; width:64px; height:3px; margin:.9rem auto 0; border-radius:99px; background:var(--ml-grad); }
    .ml-sec-head p{ color:var(--ml-muted); margin:.5rem 0 0; max-width:54ch; font-size:.85rem; }

    /* ---- buttons ---------------------------------------------------------------------- */
    .ml-btn{ display:inline-flex; align-items:center; justify-content:center; gap:.5rem;
        min-height:46px; padding:0 1.5rem; border:0; border-radius:99px;
        font-weight:700; font-size:.82rem; text-decoration:none;
        transition:transform .35s var(--ml-spring), box-shadow .3s var(--ml-ease), background .25s;
        background:var(--ml-ink); color:#fff; }
    .ml-btn:hover{ transform:translateY(-2px); box-shadow:var(--ml-shadow-lg); color:#fff; text-decoration:none; }
    .ml-btn:active{ transform:translateY(0) scale(.97); }
    .ml-btn-gold:hover{ box-shadow:var(--ml-glow); }
    .ml-btn-gold{ background:var(--ml-grad); color:#fff; }
    .ml-btn-light{ background:#fff; color:var(--ml-ink); }
    .ml-btn-light:hover{ color:var(--ml-primary); }

    /* ---- hero slider ------------------------------------------------------------------- */
    .ml-hero{ position:relative; overflow:hidden; border-radius:18px; background:var(--ml-dark); }
    .ml-hero__track{ position:relative; }
    .ml-hero__slide{ position:absolute; inset:0; opacity:0; transition:opacity .8s var(--ml-ease); pointer-events:none; }
    .ml-hero__slide.is-active{ position:relative; opacity:1; pointer-events:auto; }
    .ml-hero__media{ position:absolute; inset:0; overflow:hidden; }
    .ml-hero__media picture{ display:block; width:100%; height:100%; }
    .ml-hero__media img{ width:100%; height:100%; object-fit:cover; display:block; }
    .ml-hero.is-zoom .ml-hero__slide.is-active .ml-hero__media img{ animation:mlKenBurns 14s ease-out forwards; }
    @keyframes mlKenBurns{ from{ transform:scale(1) } to{ transform:scale(1.08) } }
    .ml-hero__scrim{ position:absolute; inset:0; }
    .ml-hero__cap{ position:relative; z-index:2; height:100%; display:flex; flex-direction:column; gap:1rem;
        justify-content:center; padding:clamp(1.5rem,6vw,4.5rem); max-width:min(660px,92%); }
    .ml-hero__cap.is-center{ margin:0 auto; text-align:center; align-items:center; }
    .ml-hero__cap.is-end{ margin-inline-start:auto; text-align:end; align-items:flex-end; }
    .ml-hero__cap h3{ font-family:var(--ml-serif); font-weight:900; letter-spacing:-.5px;
        font-size:clamp(1.9rem,4.4vw,3.4rem); line-height:1.15; margin:0; }
    .ml-hero__cap p{ font-size:clamp(.9rem,1.5vw,1rem); opacity:.92; margin:0; max-width:46ch; }
    .ml-hero__slide.is-active .ml-hero__cap > *{ animation:mlRise .8s var(--ml-ease) both; }
    .ml-hero__slide.is-active .ml-hero__cap > *:nth-child(2){ animation-delay:.1s }
    .ml-hero__slide.is-active .ml-hero__cap > *:nth-child(3){ animation-delay:.2s }
    .ml-hero__slide.is-active .ml-hero__cap > *:nth-child(4){ animation-delay:.3s }
    @keyframes mlRise{ from{ opacity:0; transform:translateY(20px) } to{ opacity:1; transform:none } }
    .ml-hero__nav{ position:absolute; top:50%; transform:translateY(-50%); z-index:3; width:42px; height:42px;
        border-radius:50%; border:1px solid rgba(255,255,255,.4); background:rgba(255,255,255,.14);
        backdrop-filter:blur(8px); color:#fff; font-size:1.05rem; line-height:1; cursor:pointer; transition:.25s var(--ml-ease); }
    .ml-hero__nav:hover{ background:#fff; color:var(--ml-ink); }
    .ml-hero__nav.is-prev{ inset-inline-start:18px } .ml-hero__nav.is-next{ inset-inline-end:18px }
    .ml-hero__dots{ position:absolute; z-index:3; bottom:16px; inset-inline:0; display:flex; gap:.45rem; justify-content:center; }
    .ml-hero__dots button{ width:8px; height:8px; border:0; padding:0; cursor:pointer; border-radius:99px;
        background:rgba(255,255,255,.42); transition:.25s; }
    .ml-hero__dots button.is-active{ background:#fff; width:26px; }

    /* ---- product cards (grid + rail) --------------------------------------------------- */
    .ml-card{ position:relative; display:flex; flex-direction:column; background:var(--ml-paper);
        border:1px solid #f0ecf8; border-radius:20px; overflow:hidden;
        transition:transform .45s var(--ml-spring), box-shadow .4s var(--ml-ease), border-color .3s; }
    .ml-card:hover{ transform:translateY(-6px); box-shadow:var(--ml-shadow-lg); border-color:#e2daf1; }
    .ml-card:active{ transform:translateY(-2px) scale(.995); }

    .ml-card__media{ position:relative; }
    .ml-card__thumb{ display:block; overflow:hidden; background:#fbfaff; aspect-ratio:1/1; }
    .ml-card__thumb img{ width:100%; height:100%; object-fit:cover; transition:transform .7s var(--ml-ease); }
    .ml-card:hover .ml-card__thumb img{ transform:scale(1.06); }

    .ml-off{ position:absolute; top:9px; inset-inline-start:9px; z-index:3; border-radius:7px;
        padding:4px 8px; color:#fff; font-size:.66rem; font-weight:800; letter-spacing:.02em;
        background:linear-gradient(135deg,#E23A3A,#F0603C); box-shadow:0 4px 12px rgba(226,58,58,.28); }
    .ml-soldout{ position:absolute; z-index:3; inset-inline-start:50%; transform:translateX(-50%); bottom:10px;
        border-radius:7px; padding:4px 10px; font-size:.66rem; font-weight:700;
        background:rgba(20,8,46,.82); color:#fff; white-space:nowrap; }

    /* Wishlist: the storefront's own action, styled as a quiet circle that fills when saved. */
    .ml-fav{ position:absolute; z-index:3; top:9px; inset-inline-end:9px; width:32px; height:32px;
        display:grid; place-items:center; padding:0; cursor:pointer; border:1px solid var(--ml-line);
        border-radius:50%; background:rgba(255,255,255,.92); color:var(--ml-ink2);
        font-size:.82rem; transition:.22s var(--ml-ease); }
    .ml-fav{ backdrop-filter:blur(8px); transition:transform .4s var(--ml-spring), color .2s, border-color .2s; }
    .ml-fav:hover{ color:#E23A3A; border-color:#f2c9c9; transform:scale(1.14); }
    .ml-fav:active{ transform:scale(.92); }
    .ml-fav.is-on,.ml-fav .fa-heart{ color:#E23A3A; }

    .ml-card__body{ display:flex; flex-direction:column; gap:6px; padding:12px; flex:1 1 auto; }
    .ml-brandline{ font-size:.66rem; font-weight:500; letter-spacing:.06em; text-transform:uppercase;
        color:var(--ml-muted); line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    /* Two full lines, clipped at a line boundary — a fixed pixel height cut glyphs in half. */
    .ml-name{ display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
        color:var(--ml-ink2); font-size:.82rem; font-weight:600; line-height:1.5; min-height:3em;
        margin:0; text-decoration:none; }
    .ml-name:hover{ color:var(--ml-primary); text-decoration:none; }
    .ml-stars{ display:flex; align-items:center; gap:2px; font-size:.66rem; color:#F5A623; line-height:1; }
    .ml-stars small{ margin-inline-start:4px; color:var(--ml-muted); font-size:.66rem; }
    .ml-price{ display:flex; align-items:baseline; flex-wrap:wrap; gap:7px; margin-top:auto;
        font-family:var(--ml-serif); color:var(--ml-ink); }
    .ml-price b{ font-weight:800; font-size:1rem; line-height:1.3; }
    .ml-price del{ font-size:.7rem; color:#aca4b8; font-weight:500; }
    .ml-cart-btn{ width:100%; display:inline-flex; align-items:center; justify-content:center; gap:.4rem;
        min-height:40px; margin-top:6px; padding:0 .8rem; border:1px solid var(--ml-line); border-radius:99px;
        cursor:pointer; background:var(--ml-sand); color:var(--ml-ink2); font-size:.75rem; font-weight:700;
        transition:transform .35s var(--ml-spring), background .25s var(--ml-ease), color .2s, box-shadow .3s; }
    .ml-cart-btn:hover:not(:disabled){ background:var(--ml-grad); color:#fff; border-color:transparent;
        box-shadow:var(--ml-glow); transform:translateY(-1px); }
    .ml-cart-btn:active:not(:disabled){ transform:scale(.97); }
    .ml-cart-btn:disabled{ opacity:.55; cursor:not-allowed; }

    .ml-rail{ display:flex; gap:12px; overflow-x:auto; scroll-behavior:smooth; scroll-snap-type:x proximity;
        scrollbar-width:none; padding:3px 2px 10px; }
    .ml-rail::-webkit-scrollbar{ display:none; }
    .ml-rail .ml-card{ min-width:224px; width:224px; flex:0 0 auto; scroll-snap-align:start; }
    .ml-rail-btns{ display:flex; gap:6px; }
    .ml-rail-btn{ width:34px; height:34px; border-radius:50%; background:#fff; border:1px solid var(--ml-line);
        display:grid; place-items:center; color:var(--ml-ink2); cursor:pointer; transition:.2s; }
    .ml-rail-btn:hover{ background:var(--ml-grad); color:#fff; border-color:transparent; }
    .ml-rail-dots{ display:flex; gap:.4rem; justify-content:center; margin-top:.35rem; }
    .ml-rail-dots button{ width:7px; height:7px; padding:0; border:0; border-radius:99px; cursor:pointer;
        background:var(--ml-line); transition:.25s var(--ml-ease); }
    .ml-rail-dots button.is-active{ width:22px; background:var(--ml-grad); }
    .ml-viewall{ font-size:.75rem; color:var(--ml-primary); font-weight:700; text-decoration:none; }
    .ml-viewall:hover{ text-decoration:underline; color:var(--ml-primary); }

    /* ---- category rings ----------------------------------------------------------------- */
    .ml-cat{ display:block; text-align:center; text-decoration:none; color:inherit; }
    .ml-cat:hover{ text-decoration:none; color:inherit; }
    .ml-cat-ring{ position:relative; width:104px; height:104px; border-radius:50%; margin:0 auto; overflow:hidden;
        display:flex; align-items:center; justify-content:center;
        background:var(--ml-sand); border:1px solid #eee8f8; transition:.25s var(--ml-ease); }
    .ml-cat-ring::after{ content:""; position:absolute; width:46px; height:46px; border-radius:50%;
        background:var(--ml-grad); opacity:.08; inset-inline-start:10px; bottom:5px; }
    .ml-cat-ring{ transition:transform .45s var(--ml-spring), box-shadow .35s var(--ml-ease), border-color .3s; }
    .ml-cat:hover .ml-cat-ring{ transform:translateY(-6px) scale(1.04); box-shadow:var(--ml-glow); border-color:transparent; }
    .ml-cat:hover .ml-cat-name{ color:var(--ml-primary); }
    .ml-cat-ring img{ position:relative; z-index:1; width:60%; height:60%; object-fit:contain; }
    .ml-cat-ring.is-letter{ background:var(--ml-grad); }
    .ml-cat-ring.is-letter span{ position:relative; z-index:1; font-family:var(--ml-serif); font-weight:800;
        font-size:2rem; color:#fff; }
    .ml-cat-name{ font-size:.78rem; font-weight:600; color:var(--ml-ink2); margin-top:.55rem; min-height:0; }

    /* ---- the other three category looks ---------------------------------------------------
       A ring is the pharmacy-counter look and it is not always the right one: a long category name
       needs a card, a photographed category deserves to BE the tile, and a shop with twenty
       departments wants them all above the fold as chips. */
    .ml-cat-card{ display:flex; align-items:center; gap:.7rem; padding:.7rem .85rem; border-radius:16px;
        background:var(--ml-paper); border:1px solid var(--ml-line); box-shadow:var(--ml-shadow);
        text-decoration:none; color:inherit; transition:transform .35s var(--ml-spring), box-shadow .35s var(--ml-ease); }
    .ml-cat-card:hover{ transform:translateY(-3px); box-shadow:var(--ml-shadow-lg); text-decoration:none; color:inherit; }
    .ml-cat-card__art{ flex:0 0 46px; width:46px; height:46px; border-radius:13px; overflow:hidden;
        display:flex; align-items:center; justify-content:center; background:var(--ml-sand); }
    .ml-cat-card__art img{ width:70%; height:70%; object-fit:contain; }
    .ml-cat-card__art.is-letter{ background:var(--ml-grad); }
    .ml-cat-card__art.is-letter span{ font-family:var(--ml-serif); font-weight:800; font-size:1.25rem; color:#fff; }
    .ml-cat-card__name{ flex:1 1 auto; font-size:.86rem; font-weight:600; color:var(--ml-ink2); }
    .ml-cat-card__go{ color:var(--ml-muted); font-size:1.3rem; line-height:1; }
    [dir="rtl"] .ml-cat-card__go{ transform:scaleX(-1); }

    .ml-cat-tile{ position:relative; display:block; border-radius:20px; overflow:hidden; aspect-ratio:4/3;
        box-shadow:var(--ml-shadow); text-decoration:none; }
    .ml-cat-tile img{ width:100%; height:100%; object-fit:cover; transition:transform .8s var(--ml-ease); }
    .ml-cat-tile:hover img{ transform:scale(1.06); }
    .ml-cat-tile__veil{ position:absolute; inset:0; background:linear-gradient(to top, rgba(20,8,46,.78) 0%, rgba(20,8,46,0) 62%); }
    .ml-cat-tile__name{ position:absolute; inset-inline:0; bottom:0; padding:.85rem .9rem; color:#fff;
        font-weight:700; font-size:.95rem; }


    /* flash deal + deal of the day: the same content as an announcement band or a compact panel. */
    .ml-flash--banner{ min-height:200px; align-items:center; justify-content:center; text-align:center; flex-direction:column; }
    .ml-flash--banner .ml-flash__copy h3{ font-size:clamp(1.4rem,3vw,2.1rem); }
    .ml-dotd--banner{ grid-template-columns:1fr; text-align:center; }
    .ml-dotd--banner .ml-dotd__card{ max-width:320px; margin-inline:auto; }
    .ml-dotd--card{ grid-template-columns:1fr; max-width:420px; margin-inline:auto; padding:1rem; }
    .ml-dotd--card .ml-dotd__copy h2{ font-size:1.15rem; }

    /* ---- new sections ----------------------------------------------------------------------
       Tabs, a brand mark, a ranked list of real searches, a get-the-app panel and price bands. */
    .ml-tabs{ display:flex; gap:.35rem; flex-wrap:wrap; }
    .ml-tabs__btn{ border:1px solid var(--ml-line); background:var(--ml-paper); color:var(--ml-ink2);
        border-radius:99px; padding:.35rem .9rem; font-size:.8rem; font-weight:600; cursor:pointer;
        transition:background .3s var(--ml-ease), color .3s, border-color .3s; }
    .ml-tabs__btn.is-active{ background:var(--ml-grad); color:#fff; border-color:transparent; }
    .ml-tabs__panel{ display:none; }
    .ml-tabs__panel.is-active{ display:block; }

    .ml-brand-mark{ width:56px; height:56px; border-radius:16px; background:var(--ml-paper);
        border:1px solid var(--ml-line); display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .ml-brand-mark img{ width:78%; height:78%; object-fit:contain; }

    .ml-trend{ list-style:none; margin:0; padding:0; display:grid;
        grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:.35rem .9rem; counter-reset:trend; }
    .ml-trend a{ display:flex; align-items:center; gap:.7rem; padding:.5rem .35rem; text-decoration:none;
        color:inherit; border-bottom:1px solid var(--ml-line); }
    .ml-trend a:hover{ text-decoration:none; color:var(--ml-primary); }
    .ml-trend__rank{ flex:0 0 26px; height:26px; border-radius:9px; background:var(--ml-sand);
        display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.78rem; color:var(--ml-primary); }
    .ml-trend__term{ flex:1 1 auto; font-size:.86rem; font-weight:600; }
    .ml-trend__count{ font-size:.74rem; color:var(--ml-muted); }

    .ml-getapp{ display:grid; grid-template-columns:minmax(0,1fr) auto; gap:1.4rem; align-items:center;
        background:var(--ml-dark); color:#fff; border-radius:26px; padding:1.6rem 1.8rem; }
    .ml-getapp h3{ color:#fff; margin:.2rem 0 .4rem; }
    .ml-getapp p{ color:rgba(255,255,255,.72); margin:0 0 .9rem; }
    .ml-getapp__badges{ display:flex; gap:.6rem; flex-wrap:wrap; }
    .ml-getapp__qr{ background:#fff; padding:.6rem; border-radius:18px; line-height:0; }
    .ml-getapp__qr svg{ width:132px; height:132px; display:block; }
    .ml-getapp__art img{ max-width:220px; border-radius:18px; }
    .ml-getapp--strip{ grid-template-columns:1fr; text-align:center; padding:1.1rem 1.3rem; }
    .ml-getapp--strip .ml-getapp__badges{ justify-content:center; }
    .ml-getapp--strip .ml-getapp__qr{ display:none; }
    @media (max-width:720px){ .ml-getapp{ grid-template-columns:1fr; text-align:center; } .ml-getapp__badges{ justify-content:center; } .ml-getapp__qr{ margin-inline:auto; } }

    .ml-price-tile{ display:flex; align-items:center; justify-content:center; min-height:118px;
        border-radius:20px; background:var(--ml-grad); background-size:cover; background-position:center;
        color:#fff; font-weight:800; font-size:1.05rem; text-decoration:none; box-shadow:var(--ml-shadow);
        transition:transform .35s var(--ml-spring), box-shadow .35s var(--ml-ease); }
    .ml-price-tile:hover{ transform:translateY(-4px); box-shadow:var(--ml-shadow-lg); color:#fff; text-decoration:none; }

    /* ---- display variants ------------------------------------------------------------------
       Every "display style" in the builder is a real difference in layout, not a renamed class.
       Each rule below is the whole of one option, kept together so the next person can see what a
       style actually changes. */

    /* product_slider: carousel peeks at the next card so the row reads as scrollable; spotlight
       gives the first product the room; list trades the thumbnail grid for a scannable column. */
    .ml-rail.is-peek{ scroll-snap-type:x mandatory; }
    .ml-rail.is-peek > *{ scroll-snap-align:start; flex:0 0 clamp(190px, 42vw, 260px); }
    .ml-spotlight{ display:grid; grid-template-columns:minmax(0,1.1fr) minmax(0,2fr); gap:1.1rem; align-items:start; }
    .ml-spotlight__hero > *{ height:100%; }
    .ml-spotlight__rest{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:1rem; }
    @media (max-width:860px){ .ml-spotlight{ grid-template-columns:1fr; } }
    .ml-plist{ display:flex; flex-direction:column; gap:.55rem; }
    .ml-plist__row{ display:flex; align-items:center; gap:.85rem; padding:.6rem .8rem; border-radius:16px;
        background:var(--ml-paper); border:1px solid var(--ml-line); text-decoration:none; color:inherit;
        transition:border-color .3s var(--ml-ease), box-shadow .3s var(--ml-ease); }
    .ml-plist__row:hover{ border-color:transparent; box-shadow:var(--ml-shadow-lg); text-decoration:none; color:inherit; }
    .ml-plist__row img{ width:56px; height:56px; border-radius:12px; object-fit:cover; flex:0 0 56px; background:var(--ml-sand); }
    .ml-plist__body{ flex:1 1 auto; display:flex; flex-direction:column; gap:.15rem; min-width:0; }
    .ml-plist__body b{ font-size:.88rem; font-weight:600; color:var(--ml-ink2); }
    .ml-plist__body small{ font-size:.72rem; color:var(--ml-muted); }
    .ml-plist__price{ font-weight:800; color:var(--ml-primary); white-space:nowrap; }

    /* coupon_strip: tickets are perforated stubs, cards are flat panels, strip is one scrolling row. */
    .ml-coupons--tickets .ml-coupon{ position:relative; }
    .ml-coupons--tickets .ml-coupon::before,
    .ml-coupons--tickets .ml-coupon::after{ content:""; position:absolute; width:18px; height:18px; border-radius:50%;
        background:var(--ml-sand); top:50%; transform:translateY(-50%); }
    .ml-coupons--tickets .ml-coupon::before{ inset-inline-start:-9px; }
    .ml-coupons--tickets .ml-coupon::after{ inset-inline-end:-9px; }
    .ml-coupons--cards .ml-coupon{ border-style:solid; }
    .ml-coupon-strip{ display:flex; gap:.75rem; overflow-x:auto; padding-bottom:.4rem; scrollbar-width:none; }
    .ml-coupon-strip::-webkit-scrollbar{ display:none; }
    .ml-coupon-strip .ml-coupon{ flex:0 0 clamp(230px, 60vw, 300px); }

    /* testimonials: cards is the default row; wall is a masonry of many short quotes, which reads
       as volume; compact drops the card to a single quoted line. */
    .ml-quotes--wall{ column-count:3; column-gap:1rem; display:block; }
    .ml-quotes--wall .ml-quote{ break-inside:avoid; margin-bottom:1rem; display:block; }
    @media (max-width:900px){ .ml-quotes--wall{ column-count:2; } }
    @media (max-width:600px){ .ml-quotes--wall{ column-count:1; } }
    .ml-quotes--compact{ display:flex; flex-direction:column; gap:.5rem; }
    .ml-quotes--compact .ml-quote{ display:flex; align-items:center; gap:.8rem; padding:.65rem .9rem; box-shadow:none;
        border:1px solid var(--ml-line); }
    .ml-quotes--compact .ml-quote__mark,
    .ml-quotes--compact .ml-quote__stars{ display:none; }
    .ml-quotes--compact .ml-quote p{ margin:0; flex:1 1 auto; font-size:.84rem; }

    /* faq: panel keeps the help card beside the questions; two_column splits a long list; cards
       turns each question into a tile for a short high-intent set. */
    .ml-faq--two_column .ml-faq__list{ column-count:2; column-gap:1.4rem; }
    .ml-faq--two_column .ml-faq__list details{ break-inside:avoid; }
    @media (max-width:820px){ .ml-faq--two_column .ml-faq__list{ column-count:1; } }
    .ml-faq--cards{ grid-template-columns:1fr; }
    .ml-faq--cards .ml-faq__list{ display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:.8rem; }
    .ml-faq--cards .ml-faq__list details{ background:var(--ml-paper); border:1px solid var(--ml-line);
        border-radius:16px; padding:.85rem 1rem; box-shadow:var(--ml-shadow); }

    /* blog_posts: list is headline-first; featured gives the newest post the width. */
    .ml-posts--list{ display:flex; flex-direction:column; gap:.6rem; }
    .ml-posts--list .ml-post{ display:flex; align-items:center; gap:.9rem; }
    .ml-posts--list .ml-post__thumb{ flex:0 0 96px; width:96px; height:70px; border-radius:12px; overflow:hidden; }
    .ml-posts--featured{ display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:1rem; }
    .ml-posts--featured .ml-post.is-lead{ grid-column:1 / -1; }
    .ml-posts--featured .ml-post.is-lead .ml-post__thumb{ aspect-ratio:21/9; }

    /* branches: a list when there are more of them than a card grid can hold. */
    .ml-branches--list{ display:flex; flex-direction:column; gap:.55rem; }
    .ml-branches--list .ml-branch{ display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:center; gap:.6rem 1rem; }
    .ml-branches--list .ml-branch h4{ grid-column:1 / -1; margin-bottom:.15rem; }

    /* newsletter: inline is one line between two sections; split puts the promise beside the field. */
    .ml-news--inline{ display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
        padding:.9rem 1.2rem; }
    .ml-news--inline h3{ margin:0; font-size:1.05rem; }
    .ml-news--inline p{ display:none; }
    .ml-news--inline .ml-news-form{ margin:0; flex:1 1 320px; max-width:520px; }
    .ml-news--split{ display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); align-items:center; gap:1.2rem;
        text-align:start; }
    .ml-news--split .ml-news-form{ margin:0; }
    @media (max-width:760px){ .ml-news--split{ grid-template-columns:1fr; } }

    /* stories: cards give the title room; bubbles are the tappable shape everyone knows. */
    .ml-stories--cards{ display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:.8rem; overflow:visible; }
    .ml-stories--cards .ml-story-dot{ flex-direction:column; width:100%; }
    .ml-stories--cards .ml-story-dot__ring{ width:100%; height:190px; border-radius:18px; padding:0; }
    .ml-stories--cards .ml-story-dot__ring img{ border-radius:18px; }
    .ml-stories--cards .ml-story-dot small{ font-size:.8rem; font-weight:600; white-space:normal; }

    /* interest tiles: circles for a compact row of concerns, rail when there are many. */
    .ml-interest--circles{ min-height:0 !important; width:132px; aspect-ratio:1; border-radius:50%; overflow:hidden; }
    .ml-interest--circles .ml-tile__body h4{ font-size:.85rem; }
    .ml-interest--circles .ml-tile__body p{ display:none; }
    .ml-interest--rail{ flex:0 0 clamp(220px, 60vw, 320px); }

    /* promotional banners: a scrolling rail, or a stagger that reads as a composition. */
    .ml-banners--overlap .ml-tile:nth-child(even){ transform:translateY(22px); }
    .ml-banners--overlap .ml-tile:nth-child(even):hover{ transform:translateY(16px); }
    .ml-banners--rail .ml-tile{ flex:0 0 clamp(240px, 66vw, 380px); }

    .ml-cat-chips{ display:flex; flex-wrap:wrap; gap:.5rem; }
    .ml-chip{ display:inline-flex; align-items:center; gap:.45rem; min-height:38px; padding:0 .95rem;
        border-radius:99px; background:var(--ml-paper); border:1px solid var(--ml-line);
        font-size:.82rem; font-weight:600; color:var(--ml-ink2); text-decoration:none;
        transition:background .3s var(--ml-ease), color .3s, border-color .3s, transform .35s var(--ml-spring); }
    .ml-chip img{ width:20px; height:20px; object-fit:contain; }
    .ml-chip:hover{ background:var(--ml-grad); color:#fff; border-color:transparent; text-decoration:none; transform:translateY(-2px); }
    .ml-chip:hover img{ filter:brightness(0) invert(1); }

    /* ---- vendors (marketplace) ------------------------------------------------------------ */
    .ml-vendor{ position:relative; display:flex; flex-direction:column; overflow:hidden;
        border-radius:20px; background:var(--ml-paper); border:1px solid #eeeaf5; text-decoration:none;
        color:inherit; transition:transform .45s var(--ml-spring), box-shadow .4s var(--ml-ease), border-color .3s; }
    .ml-vendor:hover{ transform:translateY(-6px); box-shadow:var(--ml-shadow-lg); border-color:#e2daf1;
        text-decoration:none; color:inherit; }
    .ml-vendor__cover{ display:block; aspect-ratio:16/7; overflow:hidden; background:var(--ml-sand); }
    .ml-vendor__cover img{ width:100%; height:100%; object-fit:cover; transition:transform .8s var(--ml-ease); }
    .ml-vendor:hover .ml-vendor__cover img{ transform:scale(1.07); }
    .ml-vendor__body{ display:flex; align-items:center; gap:.7rem; padding:12px 14px 16px; }
    .ml-vendor__logo{ flex:0 0 auto; width:52px; height:52px; border-radius:50%; overflow:hidden;
        background:#fff; border:2px solid #fff; box-shadow:0 6px 18px rgba(20,8,46,.14); }
    .ml-vendor:not(.is-compact) .ml-vendor__logo{ margin-top:-34px; }
    .ml-vendor__logo img{ width:100%; height:100%; object-fit:cover; }
    .ml-vendor__id{ display:flex; flex-direction:column; gap:3px; min-width:0; }
    .ml-vendor__id b{ font-size:.86rem; font-weight:700; color:var(--ml-ink); line-height:1.35;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ml-vendor__stats{ display:flex; flex-wrap:wrap; align-items:center; gap:.5rem;
        font-size:.7rem; color:var(--ml-muted); }
    .ml-vendor__rating{ display:inline-flex; align-items:center; gap:3px; padding:2px 7px; border-radius:99px;
        background:rgba(245,166,35,.13); color:#b57708; font-weight:700; }
    .ml-vendor__closed{ margin-inline-start:auto; padding:3px 9px; border-radius:99px; font-size:.62rem;
        font-weight:700; background:rgba(20,8,46,.08); color:var(--ml-ink2); white-space:nowrap; }
    .ml-vendor.is-compact{ flex-direction:row; align-items:center; padding:10px 12px; }
    .ml-vendor.is-compact .ml-vendor__body{ padding:0; }
    .ml-vendor-rail .ml-vendor{ min-width:260px; width:260px; flex:0 0 auto; scroll-snap-align:start; }

    /* one featured shop */
    .ml-shop{ position:relative; margin-bottom:1.3rem; border-radius:22px; overflow:hidden;
        background:var(--ml-paper); border:1px solid #eeeaf5; box-shadow:var(--ml-shadow); }
    .ml-shop__cover{ display:block; aspect-ratio:24/7; overflow:hidden; background:var(--ml-sand); }
    .ml-shop__cover img{ width:100%; height:100%; object-fit:cover; transition:transform 1s var(--ml-ease); }
    .ml-shop:hover .ml-shop__cover img{ transform:scale(1.04); }
    .ml-shop__bar{ display:flex; align-items:center; flex-wrap:wrap; gap:1rem; padding:14px 18px 18px; }
    .ml-shop__logo{ flex:0 0 auto; width:76px; height:76px; margin-top:-46px; border-radius:22px; overflow:hidden;
        background:#fff; border:3px solid #fff; box-shadow:0 12px 30px rgba(20,8,46,.18); }
    .ml-shop__logo img{ width:100%; height:100%; object-fit:cover; }
    .ml-shop__id{ flex:1 1 220px; min-width:0; }
    .ml-shop__id h3{ margin:0; font-family:var(--ml-serif); font-weight:800;
        font-size:clamp(1.1rem,2vw,1.45rem); color:var(--ml-ink); }
    .ml-shop__stats{ display:flex; flex-wrap:wrap; align-items:center; gap:.9rem; margin-top:.35rem;
        font-size:.76rem; color:var(--ml-muted); }
    .ml-shop__stats i{ color:#F5A623; }
    .ml-shop__closed{ padding:3px 10px; border-radius:99px; background:rgba(20,8,46,.08); color:var(--ml-ink2); font-weight:700; }
    .ml-shop__visit{ margin-inline-start:auto; }
    @media (max-width:575.98px){
        .ml-shop__logo{ width:60px; height:60px; margin-top:-34px; border-radius:18px; }
        .ml-shop__visit{ width:100%; margin-inline-start:0; }
    }

    /* ---- category showcase --------------------------------------------------------------- */
    .ml-showcase__banner{ display:block; margin-bottom:1.2rem; }
    .ml-showcase__banner picture{ display:block; }
    .ml-sections .ml-showcase__banner img{ width:100%; height:auto; object-fit:contain; }
    /* The caption is a light touch over artwork that usually carries its own text: it sits in the
       bottom corner and shrinks on a phone instead of covering the banner. */
    .ml-showcase__banner .ml-tile__scrim{ background:linear-gradient(180deg,rgba(20,8,46,0) 45%,rgba(20,8,46,.55)); }
    .ml-showcase__banner .ml-tile__body{ padding:clamp(.7rem,1.8vw,1.4rem); }
    @media (max-width:767.98px){
        .ml-showcase__banner .ml-tile__body h4{ font-size:1rem; }
        .ml-showcase__banner .ml-tile__body p{ display:none; }
    }
    .ml-chips{ display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:1rem; }
    .ml-chips a{ display:inline-flex; align-items:center; min-height:32px; padding:0 .8rem; border-radius:99px;
        background:var(--ml-sand); border:1px solid var(--ml-line); color:var(--ml-ink2);
        font-size:.74rem; font-weight:600; text-decoration:none; transition:.22s var(--ml-ease); }
    .ml-chips a:hover{ background:var(--ml-grad); color:#fff; border-color:transparent; text-decoration:none; }

    /* ---- promo tiles / mosaic / split ---------------------------------------------------- */
    .ml-tile{ position:relative; display:block; overflow:hidden; text-decoration:none; color:#fff;
        border-radius:22px; box-shadow:var(--ml-shadow); background:var(--ml-sand);
        transition:transform .45s var(--ml-spring), box-shadow .4s var(--ml-ease); }
    .ml-tile:hover{ transform:translateY(-4px); box-shadow:var(--ml-shadow-lg); }
    .ml-tile:hover{ text-decoration:none; color:#fff; }
    .ml-tile img{ width:100%; height:100%; object-fit:cover; display:block; transition:transform .8s var(--ml-ease); }
    .ml-tile:hover img{ transform:scale(1.05); }
    .ml-tile__scrim{ position:absolute; inset:0; background:linear-gradient(180deg,rgba(20,8,46,.03),rgba(20,8,46,.55)); }
    .ml-tile__body{ position:absolute; inset:auto 0 0 0; z-index:2; padding:clamp(1rem,2.2vw,1.7rem);
        display:flex; flex-direction:column; gap:.5rem; align-items:flex-start; }
    .ml-tile__body h4{ font-family:var(--ml-serif); font-weight:800; font-size:clamp(1.05rem,2vw,1.55rem); margin:0; }
    .ml-tile__body p{ margin:0; font-size:.82rem; opacity:.92; }
    .ml-tile__badge{ position:absolute; z-index:2; top:13px; inset-inline-start:13px; padding:.3rem .75rem;
        font-size:.62rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--ml-ink);
        background:rgba(255,255,255,.92); border-radius:99px; }
    .ml-tile--wide{ grid-column:span 2 } .ml-tile--tall{ grid-row:span 2 }
    .ml-tile--large{ grid-column:span 2; grid-row:span 2 }
    /* square shares small's cell in the grid; strip is the full-width rectangle row. */
    .ml-tile--strip{ grid-column:1 / -1 }
    /* A multi-frame tile crossfades through its images in place. Frames stack; .is-on shows. */
    .ml-tile--frames .ml-tile__frame{ position:absolute; inset:0; opacity:0; transition:opacity .9s var(--ml-ease); }
    .ml-tile--frames .ml-tile__frame.is-on{ opacity:1; }
    .ml-tile--frames{ min-height:80px; }
    /* The swipe row: one horizontally snap-scrolling strip; shapes set width against the height. */
    .ml-mswipe{ display:flex; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch;
        padding-bottom:6px; scrollbar-width:thin; }
    .ml-mswipe .ml-tile{ flex:0 0 auto; scroll-snap-align:start; height:var(--ml-sh,240px); }
    .ml-mswipe .ml-tile--small,.ml-mswipe .ml-tile--tall{ width:calc(var(--ml-sh,240px) * .75) }
    .ml-mswipe .ml-tile--square{ width:var(--ml-sh,240px) }
    .ml-mswipe .ml-tile--wide,.ml-mswipe .ml-tile--large{ width:calc(var(--ml-sh,240px) * 1.7) }
    .ml-mswipe .ml-tile--strip{ width:calc(var(--ml-sh,240px) * 2.4) }
    .ml-mswipe .ml-tile img{ height:100% }
    .ml-mosaic{ display:grid; grid-template-columns:repeat(4,1fr); }
    @media (max-width:767px){ .ml-mosaic{ grid-template-columns:repeat(2,1fr) } .ml-tile--large,.ml-tile--wide{ grid-column:span 2 } }
    /* Locked mosaic: the merchant's four-column composition on EVERY screen, scaled — never
       reflowed. The wrapper is the measuring container; rows derive from its live width so the
       block keeps the exact proportions it has at the 1140px design width. Browsers without
       container queries keep the fixed row height (the layout still never reflows). */
    .ml-mosaic-lockwrap{ container-type:inline-size; }
    .ml-mosaic--locked{ grid-template-columns:repeat(4,1fr) !important; }
    @supports (width: 1cqw){
        .ml-mosaic--locked{ grid-auto-rows:calc((100cqw - 3 * var(--ml-mgap,16px)) / 4 * var(--ml-mratio,.85)) !important; }
    }
    @media (max-width:767px){
        .ml-mosaic--locked .ml-tile--large, .ml-mosaic--locked .ml-tile--wide{ grid-column:span 2 }
        .ml-mosaic--locked .ml-tile--large, .ml-mosaic--locked .ml-tile--tall{ grid-row:span 2 }
        /* The locked grid ignores the two-column collapse above; captions shrink instead. */
        .ml-mosaic--locked .ml-tile__body h4{ font-size:.85rem }
        .ml-mosaic--locked .ml-tile__body{ padding:.6rem }
        .ml-mosaic--locked .ml-btn{ display:none }
    }

    .ml-split{ display:grid; grid-template-columns:repeat(2,1fr); align-items:stretch; overflow:hidden;
        border-radius:16px; border:1px solid var(--ml-line); background:#fff; }
    .ml-split.is-reversed .ml-split__media{ order:2 }
    .ml-split__media{ position:relative; overflow:hidden; }
    .ml-split__media img{ width:100%; height:100%; object-fit:cover; transition:transform 1s var(--ml-ease); }
    .ml-split:hover .ml-split__media img{ transform:scale(1.04); }
    .ml-split__body{ display:flex; flex-direction:column; justify-content:center; gap:1rem;
        padding:clamp(1.5rem,4vw,3rem); background:linear-gradient(135deg,#ede6fa,#f6faff); }
    .ml-split__body h3{ font-family:var(--ml-serif); font-weight:800; font-size:clamp(1.4rem,2.8vw,2.1rem); margin:0; color:var(--ml-ink); }
    .ml-split__body p{ color:var(--ml-muted); margin:0; max-width:46ch; font-size:.86rem; }
    .ml-split__body .ml-btn{ align-self:flex-start; }
    @media (max-width:767px){ .ml-split{ grid-template-columns:1fr } .ml-split.is-reversed .ml-split__media{ order:0 } }

    /* ---- full-width strip ---------------------------------------------------------------- */
    .ml-strip{ position:relative; display:flex; align-items:center; justify-content:center; text-align:center;
        overflow:hidden; border-radius:15px; }
    .ml-strip__bg{ position:absolute; inset:-12% 0; background-size:cover; background-position:center; }
    .ml-strip.is-parallax .ml-strip__bg{ will-change:transform; }
    .ml-strip__scrim{ position:absolute; inset:0; background:var(--ml-ink); }
    .ml-strip__body{ position:relative; z-index:2; display:flex; flex-direction:column; align-items:center;
        gap:1rem; padding:clamp(1.5rem,5vw,3rem); }
    .ml-strip__body h3{ font-family:var(--ml-serif); font-weight:900; font-size:clamp(1.5rem,3.4vw,2.5rem); margin:0; }
    .ml-strip__body p{ margin:0; opacity:.9; max-width:52ch; }

    /* ---- flash countdown strip ----------------------------------------------------------- */
    .ml-flash{ border-radius:15px; min-height:120px; background:var(--ml-grad); color:#fff;
        position:relative; overflow:hidden; padding:22px 28px;
        display:flex; align-items:center; justify-content:space-between; gap:22px; flex-wrap:wrap; }
    .ml-flash::before{ content:"\2726"; position:absolute; inset-inline-start:22px; top:-38px;
        color:#fff; font-size:140px; opacity:.08; line-height:1; }
    .ml-flash::after{ content:""; position:absolute; width:250px; height:250px; border-radius:50%;
        background:rgba(255,255,255,.09); inset-inline-end:-70px; bottom:-150px; }
    .ml-flash__copy,.ml-flash__count{ position:relative; z-index:2; }
    .ml-flash__copy h3{ font-family:var(--ml-serif); font-weight:800; font-size:1.5rem; margin:0 0 3px; color:#fff; }
    .ml-flash__copy p{ margin:0; color:#ece9ff; font-size:.78rem; }
    .ml-flash__count{ display:flex; gap:7px; direction:ltr; }
    .ml-time{ min-width:54px; padding:9px 6px; background:#fff; color:var(--ml-ink);
        border-radius:9px; text-align:center; line-height:1.1; font-weight:800; font-family:var(--ml-serif); }
    .ml-time small{ display:block; font-size:.56rem; color:var(--ml-muted); margin-top:4px; font-weight:500; }

    /* ---- brands: marquee / grid / story --------------------------------------------------- */
    .ml-marquee{ position:relative; overflow:hidden; -webkit-mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);
        mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent); }
    .ml-marquee__track{ display:flex; align-items:center; gap:3rem; width:max-content; animation:mlMarquee 32s linear infinite; }
    .ml-marquee:hover .ml-marquee__track{ animation-play-state:paused; }
    @keyframes mlMarquee{ from{ transform:translateX(0) } to{ transform:translateX(-50%) } }
    .ml-brand img{ height:52px; width:auto; max-width:150px; object-fit:contain; filter:grayscale(1); opacity:.65; transition:.3s var(--ml-ease); }
    .ml-brand:hover img{ filter:none; opacity:1; transform:translateY(-3px); }
    .ml-brandgrid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; }
    .ml-brandcard{ min-height:90px; border:1px solid var(--ml-line); border-radius:11px; background:#fff;
        display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px;
        padding:12px; color:var(--ml-ink2); font-family:var(--ml-serif); font-weight:800; font-size:.78rem;
        text-decoration:none; text-align:center; transition:.2s; }
    .ml-brandcard img{ height:34px; width:auto; max-width:110px; object-fit:contain; }
    .ml-brandcard:hover{ border-color:var(--ml-gold-soft); color:var(--ml-primary); transform:translateY(-2px); text-decoration:none; }
    .ml-story{ display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
    .ml-storycard{ min-height:300px; border-radius:15px; overflow:hidden; position:relative; padding:24px;
        display:flex; flex-direction:column; justify-content:flex-end; border:1px solid var(--ml-line);
        text-decoration:none; color:inherit; transition:.25s var(--ml-ease); }
    .ml-storycard:nth-child(3n+1){ background:linear-gradient(155deg,#efe9fb,#dfeeff); }
    .ml-storycard:nth-child(3n+2){ background:linear-gradient(155deg,#f8e9fa,#eee7fc); }
    .ml-storycard:nth-child(3n){ background:linear-gradient(155deg,#edf7ff,#e6f1fb); }
    .ml-storycard::before{ content:""; position:absolute; width:180px; height:180px; border-radius:50%;
        background:rgba(255,255,255,.55); inset-inline-start:-35px; top:-35px; }
    .ml-storycard:hover{ transform:translateY(-3px); box-shadow:var(--ml-shadow); text-decoration:none; color:inherit; }
    .ml-storycard img{ position:absolute; inset-inline-start:36px; top:34px; width:96px; height:96px;
        object-fit:contain; filter:drop-shadow(0 18px 30px rgba(49,31,79,.18)); transform:rotate(-6deg); }
    .ml-storycard h3{ font-family:var(--ml-serif); font-weight:800; color:var(--ml-ink); font-size:1.25rem; margin:0 0 6px; position:relative; z-index:2; }
    .ml-storycard span{ color:var(--ml-primary); font-size:.78rem; font-weight:700; position:relative; z-index:2; }
    @media (max-width:767px){ .ml-story{ grid-template-columns:1fr; } }

    /* ---- highlights: boxed / dark trust ---------------------------------------------------- */
    .ml-usp{ display:flex; align-items:center; gap:.9rem; text-decoration:none; color:inherit; height:100%; }
    .ml-usp:hover{ text-decoration:none; color:inherit; }
    .ml-usp.is-boxed{ padding:1.1rem 1.2rem; background:#fff; border:1px solid var(--ml-line);
        border-radius:13px; transition:.3s var(--ml-ease); }
    .ml-usp.is-boxed:hover{ transform:translateY(-3px); border-color:var(--ml-gold-soft); box-shadow:var(--ml-shadow); }
    .ml-usp__icon{ flex:0 0 auto; width:42px; height:42px; display:flex; align-items:center; justify-content:center;
        border-radius:12px; color:var(--ml-primary); background:var(--ml-sand); border:1px solid #eee8f8; }
    .ml-usp__icon svg{ width:21px; height:21px; }
    .ml-usp strong{ display:block; font-size:.85rem; color:var(--ml-ink2); }
    .ml-usp span{ display:block; font-size:.72rem; color:var(--ml-muted); }
    .ml-usp-dark{ background:var(--ml-ink); border-radius:15px; padding:clamp(1.2rem,3vw,2rem); }
    .ml-usp-dark .ml-usp{ border:1px solid rgba(255,255,255,.1); border-radius:13px; padding:1.1rem 1.2rem;
        background:rgba(255,255,255,.04); }
    .ml-usp-dark .ml-usp__icon{ background:var(--ml-grad); color:#fff; border:0; }
    .ml-usp-dark .ml-usp strong{ color:#fff; }
    .ml-usp-dark .ml-usp span{ color:#aaa2bd; }

    /* ---- testimonials ---------------------------------------------------------------------- */
    .ml-quotes{ display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:13px; }
    .ml-quote{ border:1px solid var(--ml-line); border-radius:13px; padding:20px; background:#fff; }
    .ml-quote__mark{ width:36px; height:36px; border-radius:50%; background:var(--ml-sand);
        color:var(--ml-primary); display:grid; place-items:center; font-size:22px; font-family:Georgia,serif; }
    .ml-quote__stars{ color:#f1a51b; font-size:.72rem; margin-top:.7rem; letter-spacing:2px; }
    .ml-quote p{ color:#655d75; font-size:.85rem; margin:.6rem 0 .9rem; }
    .ml-quote__who{ display:flex; align-items:center; gap:.6rem; }
    .ml-quote__avatar{ width:34px; height:34px; border-radius:50%; background:var(--ml-grad); color:#fff;
        display:grid; place-items:center; font-weight:700; flex:0 0 auto; }
    .ml-quote__who b{ display:block; color:var(--ml-ink2); font-size:.78rem; }
    .ml-quote__who small{ display:block; color:var(--ml-muted); font-size:.66rem; }

    /* ---- FAQ -------------------------------------------------------------------------------- */
    .ml-faq{ display:grid; grid-template-columns:.72fr 1.28fr; gap:28px; align-items:start; }
    .ml-faq__intro{ border-radius:15px; background:var(--ml-dark); color:#fff; padding:28px; }
    .ml-faq__intro h3{ font-family:var(--ml-serif); font-weight:800; color:#fff; font-size:1.6rem; margin:.2rem 0 .5rem; }
    .ml-faq__intro p{ color:#d9d2e9; font-size:.85rem; margin:0 0 1.1rem; }
    .ml-faq__intro .ml-eyebrow{ color:#d7c4ff; }
    .ml-faq details{ border-bottom:1px solid var(--ml-line); }
    .ml-faq summary{ list-style:none; cursor:pointer; padding:16px 0; display:flex; justify-content:space-between;
        gap:18px; color:var(--ml-ink2); font-weight:700; font-size:.9rem; }
    .ml-faq summary::-webkit-details-marker{ display:none; }
    .ml-faq summary::after{ content:"+"; color:var(--ml-primary); font-weight:700; transition:.2s; }
    .ml-faq details[open] summary::after{ transform:rotate(45deg); }
    .ml-faq details > div{ padding:0 0 16px; color:var(--ml-muted); font-size:.85rem; }
    @media (max-width:820px){ .ml-faq{ grid-template-columns:1fr; } }

    /* ---- newsletter -------------------------------------------------------------------------- */
    .ml-news{ position:relative; overflow:hidden; text-align:center; color:#fff;
        background:var(--ml-dark); border-radius:15px;
        padding:clamp(2rem,5vw,3.5rem); }
    .ml-news::before{ content:""; position:absolute; inset:0;
        background:radial-gradient(circle at 20% 0%,rgba(38,171,242,.35),transparent 55%); }
    .ml-news > *{ position:relative; z-index:1; }
    .ml-news h3{ font-family:var(--ml-serif); font-weight:800; font-size:clamp(1.4rem,2.6vw,2rem); margin:0 0 .4rem; }
    .ml-news p{ opacity:.78; margin:0 auto 1.4rem; max-width:48ch; font-size:.85rem; }
    .ml-news-form{ display:flex; gap:.5rem; max-width:460px; margin:0 auto; }
    .ml-news-form input{ flex:1; padding:.85rem 1.1rem; color:#fff; border:1px solid rgba(255,255,255,.25);
        border-radius:10px; background:rgba(255,255,255,.07); }
    .ml-news-form input::placeholder{ color:rgba(255,255,255,.55); }

    /* ---- deal of the day ---------------------------------------------------------------------- */
    .ml-dotd{ display:grid; grid-template-columns:1.05fr .95fr; gap:clamp(1rem,3vw,2.4rem); align-items:center;
        border-radius:18px; padding:clamp(1.2rem,3vw,2.4rem); background:var(--ml-dark); color:#fff;
        position:relative; overflow:hidden; }
    .ml-dotd::before{ content:""; position:absolute; inset:0;
        background:radial-gradient(circle at 85% 15%,rgba(255,255,255,.16),transparent 55%); }
    .ml-dotd > *{ position:relative; z-index:1; }
    .ml-dotd h2{ font-family:var(--ml-serif); font-weight:800; color:#fff;
        font-size:clamp(1.5rem,3.2vw,2.4rem); margin:.2rem 0 1rem; }
    .ml-dotd .ml-eyebrow{ color:#ffd9a8; }
    .ml-dotd .ml-flash__count{ margin-bottom:1.4rem; }
    .ml-dotd__card{ max-width:320px; width:100%; margin-inline:auto; }
    @media (max-width:820px){ .ml-dotd{ grid-template-columns:1fr; text-align:center; }
        .ml-dotd .ml-flash__count{ justify-content:center; } }

    /* ---- coupons ------------------------------------------------------------------------------ */
    .ml-coupon{ position:relative; display:flex; flex-direction:column; gap:.7rem; padding:1.1rem 1.2rem;
        border-radius:15px; background:#fff; border:1px dashed var(--ml-primary);
        box-shadow:var(--ml-shadow); }
    /* The perforation reads as a coupon without punching holes: two notches drawn INTO the card,
       so they never have to guess the page colour behind them. */
    .ml-coupon::before,.ml-coupon::after{ content:""; position:absolute; width:16px; height:16px;
        border-radius:50%; top:50%; transform:translateY(-50%);
        background:var(--ml-sand); box-shadow:inset 0 0 0 1px var(--ml-line); }
    .ml-coupon::before{ inset-inline-start:-8px; } .ml-coupon::after{ inset-inline-end:-8px; }
    .ml-coupon__value{ font-family:var(--ml-serif); font-weight:800; font-size:1.7rem; line-height:1;
        color:var(--ml-primary); }
    .ml-coupon__body b{ display:block; color:var(--ml-ink2); font-size:.86rem; }
    .ml-coupon__body small{ display:block; color:var(--ml-muted); font-size:.7rem; }
    .ml-coupon__code{ display:flex; align-items:center; justify-content:space-between; gap:.6rem; width:100%;
        margin-top:auto; padding:.6rem .8rem; border:0; border-radius:10px; cursor:pointer;
        background:var(--ml-sand); color:var(--ml-ink2); font-weight:700; letter-spacing:.06em;
        transition:transform .25s var(--ml-spring), background .2s; }
    .ml-coupon__code:hover{ background:var(--ml-grad); color:#fff; }
    .ml-coupon__code.is-copied{ background:#1f9d55; color:#fff; transform:scale(1.03); }

    /* ---- stats bar ---------------------------------------------------------------------------- */
    .ml-stat{ display:flex; flex-direction:column; align-items:center; gap:.45rem; text-align:center;
        padding:1.4rem 1rem; border-radius:15px; background:#fff; border:1px solid var(--ml-line); }
    .ml-usp-dark .ml-stat{ background:rgba(255,255,255,.05); border-color:rgba(255,255,255,.1); }
    .ml-stat__icon{ width:44px; height:44px; border-radius:13px; display:grid; place-items:center;
        background:var(--ml-sand); color:var(--ml-primary); }
    .ml-usp-dark .ml-stat__icon{ background:var(--ml-grad); color:#fff; }
    .ml-stat b{ font-family:var(--ml-serif); font-weight:800; font-size:clamp(1.4rem,3vw,2.1rem);
        color:var(--ml-ink2); line-height:1; }
    .ml-usp-dark .ml-stat b{ color:#fff; }
    .ml-stat__label{ color:var(--ml-muted); font-size:.76rem; }
    .ml-usp-dark .ml-stat__label{ color:#aaa2bd; }

    /* ---- bundle ------------------------------------------------------------------------------- */
    .ml-bundle{ border-radius:18px; background:#fff; border:1px solid var(--ml-line);
        padding:clamp(1.2rem,3vw,2rem); box-shadow:var(--ml-shadow); }
    .ml-bundle__head h2{ font-family:var(--ml-serif); font-weight:800; margin:.2rem 0 .3rem;
        font-size:clamp(1.25rem,2.6vw,1.8rem); color:var(--ml-ink2); }
    .ml-bundle__head p{ color:var(--ml-muted); font-size:.85rem; margin:0; }
    /* Aligned from the top, with the names clamped to two lines: a three-line name must not push
       its own tile down and knock the row out of line. */
    .ml-bundle__items{ display:flex; align-items:flex-start; flex-wrap:wrap; gap:.7rem; margin:1.3rem 0; }
    .ml-bundle__item{ width:118px; text-align:center; text-decoration:none; }
    .ml-bundle__item img{ width:100%; aspect-ratio:1; object-fit:contain; border-radius:13px;
        background:var(--ml-sand); padding:.5rem; transition:transform .35s var(--ml-spring); }
    .ml-bundle__item:hover img{ transform:translateY(-4px); }
    .ml-bundle__item span{ display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
        overflow:hidden; margin-top:.4rem; min-height:2rem; font-size:.72rem; color:var(--ml-muted); line-height:1.35; }
    .ml-bundle__plus{ font-size:1.3rem; color:var(--ml-primary); font-weight:700; margin-top:48px; }
    .ml-bundle__foot{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem;
        padding-top:1.1rem; border-top:1px dashed var(--ml-line); }
    .ml-bundle__price{ display:flex; align-items:baseline; gap:.6rem; flex-wrap:wrap; }
    .ml-bundle__price b{ font-size:1.5rem; font-weight:800; color:var(--ml-ink2); }
    .ml-bundle__price del{ color:var(--ml-muted); font-size:.9rem; }
    .ml-bundle .ml-off{ position:static; inset:auto; }

    /* ---- shop by interest --------------------------------------------------------------------- */
    .ml-interest .ml-tile__body h4{ font-family:var(--ml-serif); font-weight:800; margin:.2rem 0 .25rem;
        font-size:clamp(1.05rem,2.2vw,1.5rem); color:inherit; }
    .ml-interest .ml-tile__body p{ margin:0; font-size:.8rem; opacity:.85; }

    /* ---- stories ------------------------------------------------------------------------------ */
    .ml-stories{ display:flex; gap:14px; overflow-x:auto; padding:.3rem .1rem 1rem; scrollbar-width:none; }
    .ml-stories::-webkit-scrollbar{ display:none; }
    .ml-story-dot{ flex:0 0 auto; width:86px; border:0; background:none; padding:0; cursor:pointer; text-align:center; }
    .ml-story-dot__ring{ display:block; width:86px; height:86px; border-radius:50%; padding:3px;
        background:var(--ml-grad); transition:transform .35s var(--ml-spring); }
    .ml-story-dot:hover .ml-story-dot__ring{ transform:scale(1.06); }
    .ml-story-dot__ring img{ width:100%; height:100%; border-radius:50%; object-fit:cover;
        border:3px solid #fff; background:#fff; }
    .ml-story-dot small{ display:block; margin-top:.4rem; font-size:.7rem; color:var(--ml-ink2);
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .ml-story-viewer{ position:fixed; inset:0; z-index:1080; background:rgba(12,6,26,.94);
        display:grid; place-items:center; padding:2vh 1rem; }
    .ml-story-viewer[hidden]{ display:none; }
    .ml-story-viewer__close{ position:absolute; top:14px; inset-inline-end:18px; width:40px; height:40px;
        border:0; border-radius:50%; background:rgba(255,255,255,.14); color:#fff; font-size:26px; line-height:1;
        cursor:pointer; }
    .ml-story-viewer__stage{ width:min(420px,100%); height:min(88vh,760px); position:relative; }
    .ml-story-slide{ position:absolute; inset:0; margin:0; border-radius:18px; overflow:hidden; background:#000; }
    .ml-story-slide[hidden]{ display:none; }
    .ml-story-slide img,.ml-story-slide video{ width:100%; height:100%; object-fit:cover; }
    .ml-story-slide figcaption{ position:absolute; inset-inline:0; bottom:0; padding:1.4rem 1.2rem;
        background:linear-gradient(180deg,transparent,rgba(0,0,0,.82)); color:#fff; text-align:center; }
    .ml-story-slide figcaption b{ display:block; margin-bottom:.7rem; font-size:1rem; }

    /* ---- blog posts --------------------------------------------------------------------------- */
    .ml-post{ display:block; text-decoration:none; border-radius:15px; overflow:hidden; background:#fff;
        border:1px solid var(--ml-line); transition:transform .35s var(--ml-spring), box-shadow .35s; }
    .ml-post:hover{ transform:translateY(-5px); box-shadow:var(--ml-shadow-lg); text-decoration:none; }
    .ml-post__thumb{ display:block; aspect-ratio:16/10; background:var(--ml-sand); }
    .ml-post__thumb img{ width:100%; height:100%; object-fit:cover; }
    .ml-post__body{ display:block; padding:1rem 1.1rem 1.2rem; }
    .ml-post__body small{ display:block; color:var(--ml-primary); font-size:.68rem; letter-spacing:.08em;
        text-transform:uppercase; margin-bottom:.35rem; }
    .ml-post__body b{ display:block; color:var(--ml-ink2); font-size:.92rem; line-height:1.45; }

    /* ---- branches ----------------------------------------------------------------------------- */
    .ml-branch{ border-radius:15px; background:#fff; border:1px solid var(--ml-line); padding:1.3rem 1.4rem; }
    .ml-branch h4{ font-family:var(--ml-serif); font-weight:800; font-size:1.05rem; color:var(--ml-ink2);
        margin:0 0 .8rem; }
    .ml-branch p{ display:flex; align-items:flex-start; gap:.55rem; margin:0 0 .5rem;
        color:var(--ml-muted); font-size:.82rem; line-height:1.55; }
    .ml-branch p i{ color:var(--ml-primary); margin-top:.15rem; }
    .ml-branch a{ color:inherit; }
    .ml-branch .ml-btn{ margin-top:.6rem; border:1px solid var(--ml-line); }

    /* ---- shipping cut-off --------------------------------------------------------------------- */
    .ml-cutoff{ display:flex; align-items:center; gap:1rem; padding:1rem 1.3rem; border-radius:14px;
        background:var(--ml-sand); border:1px solid var(--ml-line); }
    .ml-cutoff.is-card{ background:var(--ml-dark); border-color:transparent; color:#fff; padding:1.5rem; }
    .ml-cutoff i{ font-size:1.6rem; color:var(--ml-primary); }
    .ml-cutoff.is-card i{ color:#ffd9a8; }
    .ml-cutoff b{ display:block; color:var(--ml-ink2); font-size:.95rem; }
    .ml-cutoff.is-card b{ color:#fff; }
    .ml-cutoff small{ display:block; color:var(--ml-muted); font-size:.76rem; }
    .ml-cutoff.is-card small{ color:#d9d2e9; }
    .ml-cutoff__clock{ display:inline-flex; gap:.15rem; font-variant-numeric:tabular-nums;
        direction:ltr; unicode-bidi:isolate; font-weight:800; color:var(--ml-primary); }
    .ml-cutoff.is-card .ml-cutoff__clock{ color:#ffd9a8; }

    /* ---- before / after ------------------------------------------------------------------------ */
    /* The legacy storefront caps every <figure> at 200px with !important; these two are stages, not
       thumbnails, so they opt out explicitly. */
    .ml-ba, .ml-story-slide{ max-height:none !important; }
    /* Physical left/right throughout: a comparison has a left and a right image, not a start and an
       end, so the reveal reads the same in Arabic as in English. Only the handle is direction-
       locked, because a range input runs backwards in RTL. */
    .ml-ba{ position:relative; margin:0; border-radius:15px; overflow:hidden;
        background:var(--ml-sand); user-select:none; touch-action:pan-y; }
    .ml-ba img{ width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; }
    .ml-ba__after{ position:absolute; top:0; bottom:0; left:0; overflow:hidden; }
    /* Sized to the FIGURE, not to the clipped strip, so both photos stay in register while the
       handle moves. The width comes from the script, which knows the rendered box. */
    .ml-ba__after img{ position:absolute; top:0; left:0; height:100%; max-width:none;
        width:var(--ml-ba-w,100%); }
    .ml-ba__range{ position:absolute; inset:0; direction:ltr; width:100%; height:100%; margin:0;
        opacity:0; cursor:ew-resize; }
    .ml-ba::after{ content:""; position:absolute; top:0; bottom:0; left:var(--ml-ba,50%);
        width:2px; background:#fff; box-shadow:0 0 0 1px rgba(0,0,0,.15); pointer-events:none; }
    .ml-ba__tag{ position:absolute; top:12px; padding:.25rem .7rem; border-radius:99px; font-size:.68rem;
        font-weight:700; background:rgba(255,255,255,.9); color:var(--ml-ink2); pointer-events:none; }
    .ml-ba__tag--after{ left:12px; } .ml-ba__tag--before{ right:12px; }
    .ml-ba figcaption{ position:absolute; left:0; right:0; bottom:0; padding:1.6rem 1.1rem .9rem; color:#fff;
        text-align:start;
        background:linear-gradient(180deg,transparent,rgba(0,0,0,.7)); pointer-events:none; }
    .ml-ba figcaption b{ display:block; font-size:.92rem; }
    .ml-ba figcaption span{ font-size:.75rem; opacity:.85; }

    @media (prefers-reduced-motion: reduce){
        .ml-sections *{ animation:none !important; transition-duration:.01ms !important; }
        .ml-reveal{ opacity:1; transform:none; }
    }
</style>

@php
    // Flash deals already rendered on this page. Only one deal can be active at a time (the
    // dashboard deactivates the rest), so a second automatic section would otherwise repeat the
    // first one instead of moving on to the next deal.
    $__shownDeals = [];
    // One query for the whole page: every card draws its heart from this list.
    $__wishlisted = $__data->wishlistedProductIds();
@endphp
<div class="theme-builder-sections ml-sections">
    @foreach ($__sections as $__section)
        @php
            $type = $__section['type'] ?? null;
            $s = $__section['settings'] ?? [];
            $blocks = $__data->blockCards($__section['blocks'] ?? []);
            $pt = (int) ($s['padding_top'] ?? 56);
            $pb = (int) ($s['padding_bottom'] ?? 56);
            $bg = $s['background'] ?? null;
            $full = ($s['width'] ?? 'container') === 'full';
            $gap = (int) ($s['gap'] ?? 16);
            $cols = max(1, (int) ($s['columns'] ?? 4));
            $align = in_array($s['alignment'] ?? 'start', ['center', 'end'], true) ? $s['alignment'] : 'start';
            $sectionKey = 'tbs-' . ($__section['id'] ?? $loop->index);
            $height = isset($s['height']) && $s['height'] !== '' && $s['height'] !== null ? (int) $s['height'] : null;
            $wrapStyle = "padding-top:{$pt}px;padding-bottom:{$pb}px;--tb-cols:{$cols};--tb-gap:{$gap}px;"
                . ($height !== null ? "--tb-h:{$height}px;" : '')
                . ($bg ? "background:{$bg};" : '');
            $breakpointCss = theme_section_breakpoint_css(settings: $s, selector: '#' . $sectionKey);

            // Sections whose whole content comes from the catalogue are resolved BEFORE the
            // wrapper is opened: with nothing to draw they must not leave a padded empty band on
            // the page, which reads as a broken gap rather than an absent section.
            $deal = $type === 'flash_deal'
                ? $__data->flashDeal((int) ($s['deal_id'] ?? 0) ?: null, $__shownDeals)
                : null;
            if ($deal) { $__shownDeals[] = $deal['id']; }
            $showcase = $type === 'category_showcase' ? $__data->categoryShowcase($s) : null;
            $vendors = $type === 'vendor_slider'
                ? $__data->vendors((int) ($s['limit'] ?? 8), $s['shop_ids'] ?? null)
                : collect();
            $vendorShowcase = $type === 'vendor_showcase' ? $__data->vendorShowcase($s) : null;
            $dotd = $type === 'deal_of_the_day' ? $__data->dealOfTheDay() : null;
            $offerProducts = match ($type) {
                'featured_deal' => $__data->featuredDealProducts((int) ($s['limit'] ?? 10)),
                'clearance_sale' => $__data->clearanceProducts((int) ($s['limit'] ?? 10)),
                default => collect(),
            };
            $coupons = $type === 'coupon_strip' ? $__data->coupons((int) ($s['limit'] ?? 4)) : collect();
            $set = $type === 'bundle' ? $__data->bundle($s) : null;
            $posts = $type === 'blog_posts' ? $__data->blogPosts((int) ($s['limit'] ?? 3)) : collect();
            $secondsLeft = $type === 'shipping_cutoff' ? $__data->shippingCutoff((string) ($s['cutoff'] ?? '16:00')) : null;
            $brandShowcase = $type === 'brand_showcase' ? $__data->brandShowcase($s) : null;
            $searchTerms = $type === 'trending_searches'
                ? $__data->trendingSearches((int) ($s['days'] ?? 30), (int) ($s['limit'] ?? 10))
                : collect();
            $seenProducts = $type === 'recently_viewed' ? $__data->recentlyViewed((int) ($s['limit'] ?? 8)) : collect();
            $appStores = $type === 'app_download'
                ? array_filter([
                    'android' => app(\App\Services\DeepLink\AppLinkService::class)->storeUrl('android'),
                    'ios' => app(\App\Services\DeepLink\AppLinkService::class)->storeUrl('ios'),
                ])
                : [];
            // Block-driven sections are nothing but their blocks: one whose blocks carry no
            // content yet would open a padded band with nothing inside it.
            $rawBlocks = match ($type) {
                'stories' => $__data->blocksWithContent($__section['blocks'] ?? [], either: ['image', 'video']),
                'branches' => $__data->blocksWithContent($__section['blocks'] ?? [], required: ['title']),
                'before_after' => $__data->blocksWithContent($__section['blocks'] ?? [], required: ['image', 'after']),
                default => $__section['blocks'] ?? [],
            };
        @endphp

        {{-- One rule, asked once. It used to be eleven @continue lines here and nothing anywhere
             else, so a section that would never render looked identical in the builder to one that
             works. SectionReadiness holds the rule now; the builder asks the same object WHY, and a
             test holds the two answers together. Nothing is re-queried: what the view already
             resolved is handed over. --}}
        @continue(($s['visible'] ?? true) === false || !in_array($type, $__renderable, true))
        @continue(!$__ready->willRender($type, $s, $rawBlocks, [
            'deal' => $deal,
            'showcase' => $showcase,
            'vendors' => $vendors->all(),
            'vendorShowcase' => $vendorShowcase,
            'dealOfTheDay' => $dotd,
            'offerProducts' => $offerProducts->all(),
            'coupons' => $coupons->all(),
            'bundle' => $set,
            'posts' => $posts->all(),
            'secondsLeft' => $secondsLeft,
            'brandShowcase' => $brandShowcase,
            'searchTerms' => $searchTerms->all(),
            'seenProducts' => $seenProducts->all(),
            'appStores' => $appStores,
        ]))

        @if ($breakpointCss)<style>{!! $breakpointCss !!}</style>@endif
        <section id="{{ $sectionKey }}" class="tbs tbs-{{ $type }} tbs-align-{{ $align }}" style="{{ $wrapStyle }}"
                 data-tb-section="{{ $__section['id'] ?? '' }}">
            <div class="{{ $full ? 'container-fluid px-0' : 'container' }}">
                @switch($type)

                    @case('hero_banner')
                        @include('theme-sections.partials.hero', ['slides' => $blocks, 'settings' => $s, 'index' => $loop->index, 'placeholder' => $__placeholder])
                        @break

                    {{-- Four ways to enter the catalogue, because the right one depends on what the
                         merchant sells: rings read as a pharmacy's departments, cards give the name
                         room when it is long, tiles turn the category itself into the artwork, and
                         chips fit twenty of them above the fold. --}}
                    @case('category_grid')
                        @php
                            $cats = $__data->categories(limit: (int) ($s['limit'] ?? 12), picked: $s['category_ids'] ?? null);
                            $catStyle = $s['style'] ?? 'circles';
                        @endphp
                        @if ($cats->isNotEmpty())
                            <div class="ml-sec-head ml-reveal">
                                <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('shop_by_category') }}</span>
                                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                <div class="ml-rule"></div>
                            </div>
                            <div class="{{ $catStyle === 'chips' ? 'ml-cat-chips' : 'ml-grid' }} ml-cats--{{ $catStyle }}">
                                @foreach ($cats as $cat)
                                    @php $catIcon = category_icon_url($cat); @endphp
                                    @if ($catStyle === 'chips')
                                        <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-chip ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                            @if ($catIcon)<img src="{{ $catIcon }}" alt="" loading="lazy">@endif
                                            <span>{{ $cat->name }}</span>
                                        </a>
                                    @elseif ($catStyle === 'tiles')
                                        <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                            <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-cat-tile">
                                                <img src="{{ $catIcon ?: $__placeholder }}" alt="{{ $cat->name }}" loading="lazy">
                                                <span class="ml-cat-tile__veil"></span>
                                                <span class="ml-cat-tile__name">{{ $cat->name }}</span>
                                            </a>
                                        </div>
                                    @elseif ($catStyle === 'cards')
                                        <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                            <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-cat-card">
                                                <span class="ml-cat-card__art {{ $catIcon ? '' : 'is-letter' }}">
                                                    @if ($catIcon)
                                                        <img src="{{ $catIcon }}" alt="{{ $cat->name }}" loading="lazy">
                                                    @else
                                                        <span aria-hidden="true">{{ mb_substr(trim((string) $cat->name), 0, 1) }}</span>
                                                    @endif
                                                </span>
                                                <span class="ml-cat-card__name">{{ $cat->name }}</span>
                                                <span class="ml-cat-card__go" aria-hidden="true">&rsaquo;</span>
                                            </a>
                                        </div>
                                    @else
                                        <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                            <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-cat">
                                                <span class="ml-cat-ring {{ $catIcon ? '' : 'is-letter' }}">
                                                    @if ($catIcon)
                                                        <img src="{{ $catIcon }}" alt="{{ $cat->name }}" loading="lazy">
                                                    @else
                                                        <span aria-hidden="true">{{ mb_substr(trim((string) $cat->name), 0, 1) }}</span>
                                                    @endif
                                                </span>
                                                <span class="ml-name ml-cat-name">{{ $cat->name }}</span>
                                            </a>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @break

                    {{-- Two presentations of the same row: a horizontal rail with arrow controls
                         (the mockup's shelf) or a plain responsive grid — the merchant picks in
                         the builder's "display style". --}}
                    @case('product_slider')
                        @php
                            $products = $__data->products($s);
                            // Five presentations of one row of products, because the right one
                            // depends on what is being sold: a rail for browsing, a grid for
                            // comparing, a peeking carousel that says "there is more", a spotlight
                            // when one product carries the section, and a list when the detail
                            // beside the name is what decides the sale — pack size, dosage, brand.
                            $railStyle = $s['style'] ?? 'rail';
                            $isRail = in_array($railStyle, ['rail', 'carousel'], true);
                            $railId = 'ml-rail-' . ($__section['id'] ?? $loop->index);
                            $railAutoplay = $isRail && ($s['autoplay'] ?? false);
                            $railInterval = max(2000, (int) ($s['interval'] ?? 4000));
                            $showDots = $isRail && ($s['pagination'] ?? false);
                            $cardCart = (bool) ($s['add_to_cart'] ?? true);
                        @endphp
                        @if ($products->isNotEmpty())
                            <div class="ml-sec-head ml-reveal">
                                <div>
                                    <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('curated_for_you') }}</span>
                                    @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                    @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    @if ($isRail && ($s['arrows'] ?? true))
                                        <div class="ml-rail-btns">
                                            <button type="button" class="ml-rail-btn" data-ml-rail="{{ $railId }}" data-dir="-1" aria-label="{{ translate('previous') }}">&#8249;</button>
                                            <button type="button" class="ml-rail-btn" data-ml-rail="{{ $railId }}" data-dir="1" aria-label="{{ translate('next') }}">&#8250;</button>
                                        </div>
                                    @endif
                                    @if ($s['view_all'] ?? true)
                                        <a class="ml-viewall" href="{{ route('products') }}">{{ translate('view_all') }}</a>
                                    @endif
                                </div>
                            </div>

                            @if ($railStyle === 'spotlight')
                                {{-- The first product is the section; the rest are what else is in
                                     the family. Two grids rather than one so the hero keeps its
                                     size on a narrow screen instead of collapsing to a card. --}}
                                <div class="ml-spotlight">
                                    <div class="ml-spotlight__hero ml-reveal">
                                        @include('theme-sections.partials.product-card', ['product' => $products->first(), 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                    </div>
                                    <div class="ml-spotlight__rest">
                                        @foreach ($products->slice(1) as $product)
                                            <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                                @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @elseif ($railStyle === 'list')
                                {{-- One product per row, name and price on one line. For a pharmacy
                                     this is the format that actually compares: pack size and brand
                                     are readable instead of clipped under a square thumbnail. --}}
                                <div class="ml-plist">
                                    @foreach ($products as $product)
                                        @php $listPrice = getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'value'); @endphp
                                        <a class="ml-plist__row ml-reveal" data-delay="{{ $loop->index % 6 }}"
                                           href="{{ route('product', ['slug' => $product->slug]) }}">
                                            <img src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'product') }}" alt="{{ $product->name }}" loading="lazy">
                                            <span class="ml-plist__body">
                                                <b>{{ Str::limit($product->name, 70) }}</b>
                                                @if (!empty($product->brand?->name))<small>{{ $product->brand->name }}</small>@endif
                                            </span>
                                            <span class="ml-plist__price">{{ webCurrencyConverter(amount: $listPrice) }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @elseif ($isRail)
                                <div class="ml-rail ml-reveal {{ $railStyle === 'carousel' ? 'is-peek' : '' }}" id="{{ $railId }}"
                                     @if ($railAutoplay) data-ml-rail-auto="{{ $railInterval }}" @endif>
                                    @foreach ($products as $product)
                                        @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                    @endforeach
                                </div>
                                @if ($showDots)
                                    <div class="ml-rail-dots" data-ml-rail-dots="{{ $railId }}"></div>
                                @endif
                            @elseif ($railStyle === 'grid')
                                <div class="ml-grid">
                                    @foreach ($products as $product)
                                        <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                            @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                {{-- A style that is no longer offered lands here rather than on a
                                     blank row: the grid is the arrangement that needs nothing. --}}
                                <div class="ml-grid">
                                    @foreach ($products as $product)
                                        <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                            @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                        @break

                    {{-- Live countdown to the END DATE of a flash deal (Promotion -> Flash deals),
                         plus the deal's own products so the strip sells and not only counts down.
                         The merchant either picks a deal or leaves it on "whichever is running";
                         with no deal at all the section renders nothing rather than a dead timer. --}}
                    @case('flash_deal')
                        @php
                            $dealProducts = ($deal && ($s['products'] ?? true))
                                ? $__data->flashDealProducts($deal['id'], (int) ($s['limit'] ?? 10))
                                : collect();
                            $cardCart = (bool) ($s['add_to_cart'] ?? true);
                            $dealRailId = 'ml-deal-' . ($__section['id'] ?? $loop->index);
                            // strip: the gradient bar with the clock beside the copy.
                            // banner: the deal's own artwork behind the clock, for a campaign that
                            //         was designed rather than assembled.
                            // grid:   no rail — every product in the deal at once, which is what a
                            //         short deal with six products actually wants.
                            $dealStyle = $s['style'] ?? 'strip';
                        @endphp
                        @if ($deal)
                            <div class="ml-flash ml-flash--{{ $dealStyle }} ml-reveal"
                                 @if ($dealStyle === 'banner' && !empty($deal['banner'])) style="background-image:linear-gradient(120deg,rgba(20,8,46,.72),rgba(20,8,46,.35)),url('{{ $deal['banner'] }}');background-size:cover;background-position:center" @endif>
                                <div class="ml-flash__copy">
                                    <h3>{{ $s['title'] ?: ($deal['title'] ?: translate('flash_deals')) }}</h3>
                                    <p>{{ $s['subtitle'] ?: translate('grab_the_best_offers_before_time_runs_out') }}</p>
                                </div>
                                @if (($s['countdown'] ?? true) && $deal['end_timestamp'])
                                    <div class="ml-flash__count" data-ml-countdown="{{ $deal['end_timestamp'] }}">
                                        <div class="ml-time"><b data-unit="days">00</b><small>{{ translate('days') }}</small></div>
                                        <div class="ml-time"><b data-unit="hours">00</b><small>{{ translate('hours') }}</small></div>
                                        <div class="ml-time"><b data-unit="minutes">00</b><small>{{ translate('minutes') }}</small></div>
                                        <div class="ml-time"><b data-unit="seconds">00</b><small>{{ translate('seconds') }}</small></div>
                                    </div>
                                @endif
                                @if ($deal['url'])
                                    <a href="{{ $deal['url'] }}" class="ml-btn ml-btn-light">{{ translate('shop_the_deal') }}</a>
                                @endif
                            </div>

                            @if ($dealProducts->isNotEmpty())
                                <div class="{{ $dealStyle === 'grid' ? 'ml-grid' : 'ml-rail' }} ml-reveal mt-3" id="{{ $dealRailId }}">
                                    @foreach ($dealProducts as $product)
                                        @if ($dealStyle === 'grid')
                                            <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                                @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                            </div>
                                        @else
                                            @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        @endif
                        @break

                    {{-- One category as its own block: its page banner, its sub-category chips and
                         its products (from the category and everything filed under it). The banner
                         is the same row the category page shows, so editing it in Banner Setup or
                         on the category form updates both. --}}
                    @case('category_showcase')
                        @php
                            $cardCart = (bool) ($s['add_to_cart'] ?? true);
                            $showcaseStyle = $s['style'] ?? 'rail';
                            $showcaseRail = $showcaseStyle !== 'grid';
                            $showcaseId = 'ml-showcase-' . ($__section['id'] ?? $loop->index);
                        @endphp
                        @if ($showcase)
                            @php $categoryUrl = route('products', ['category_id' => $showcase['category']->id]); @endphp

                            @if ($showcase['banner'])
                                <a class="ml-tile ml-showcase__banner ml-reveal"
                                   href="{{ $showcase['banner']['link'] ?: $categoryUrl }}">
                                    {{-- Shown whole, at the artwork's own proportions: a banner is
                                         designed art, and cropping it to a fixed band cuts the
                                         merchant's own text off. A phone image is used when the
                                         banner carries one. --}}
                                    <picture>
                                        @if (!empty($showcase['banner']['image_mobile']))
                                            <source media="(max-width:767.98px)" srcset="{{ $showcase['banner']['image_mobile'] }}">
                                        @endif
                                        <img src="{{ $showcase['banner']['image'] ?: $__placeholder }}"
                                             alt="{{ $showcase['banner']['title'] ?? $showcase['category']->name }}" loading="lazy">
                                    </picture>
                                    @if (!empty($showcase['banner']['title']) || !empty($showcase['banner']['subtitle']))
                                        <span class="ml-tile__scrim"></span>
                                        <span class="ml-tile__body">
                                            @if (!empty($showcase['banner']['title']))<h4>{{ $showcase['banner']['title'] }}</h4>@endif
                                            @if (!empty($showcase['banner']['subtitle']))<p>{{ $showcase['banner']['subtitle'] }}</p>@endif
                                            @if (!empty($showcase['banner']['button_text']))
                                                <span class="ml-btn ml-btn-light">{{ $showcase['banner']['button_text'] }}</span>
                                            @endif
                                        </span>
                                    @endif
                                </a>
                            @endif

                            <div class="ml-sec-head ml-reveal">
                                <div>
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    <h2>{{ $s['title'] ?: $showcase['category']->name }}</h2>
                                </div>
                                @if ($s['view_all'] ?? true)
                                    <a class="ml-viewall" href="{{ $categoryUrl }}">{{ translate('view_all') }}</a>
                                @endif
                            </div>

                            @if ($showcase['sub_categories']->isNotEmpty())
                                <div class="ml-chips ml-reveal">
                                    @foreach ($showcase['sub_categories'] as $subCategory)
                                        <a href="{{ route('products', ['category_id' => $subCategory->id]) }}">{{ $subCategory->name }}</a>
                                    @endforeach
                                </div>
                            @endif

                            @if ($showcase['products']->isNotEmpty())
                                @if ($showcaseRail)
                                    <div class="ml-rail ml-reveal" id="{{ $showcaseId }}">
                                        @foreach ($showcase['products'] as $product)
                                            @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                        @endforeach
                                    </div>
                                @else
                                    <div class="ml-grid">
                                        @foreach ($showcase['products'] as $product)
                                            <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                                @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                        @endif
                        @break

                    {{-- Today's deal: the product the merchant set in Promotion -> Deal of the day,
                         counting down to midnight because that is when the deal actually ends. --}}
                    @case('deal_of_the_day')
                        @php
                            $cardCart = (bool) ($s['add_to_cart'] ?? true);
                            // split: copy on one side, the product card on the other.
                            // banner: one wide band, the clock centred — reads as an announcement.
                            // card:   a single compact panel for a page that already has three deals.
                            $dotdStyle = $s['style'] ?? 'split';
                        @endphp
                            <div class="ml-dotd ml-dotd--{{ $dotdStyle }} ml-reveal">
                                <div class="ml-dotd__copy">
                                    <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('deal_of_the_day') }}</span>
                                    <h2>{{ $s['title'] ?: ($dotd['deal']->title ?: $dotd['product']->name) }}</h2>
                                    @if ($s['countdown'] ?? true)
                                        <div class="ml-flash__count" data-ml-countdown="{{ now()->endOfDay()->getTimestamp() }}">
                                            <div class="ml-time"><b data-unit="hours">00</b><small>{{ translate('hours') }}</small></div>
                                            <div class="ml-time"><b data-unit="minutes">00</b><small>{{ translate('minutes') }}</small></div>
                                            <div class="ml-time"><b data-unit="seconds">00</b><small>{{ translate('seconds') }}</small></div>
                                        </div>
                                    @endif
                                    <a class="ml-btn ml-btn-gold" href="{{ route('product', $dotd['product']->slug) }}">{{ translate('shop_the_deal') }}</a>
                                </div>
                                <div class="ml-dotd__card">
                                    @include('theme-sections.partials.product-card', ['product' => $dotd['product'], 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                </div>
                            </div>
                        @break

                    {{-- The running featured-deal campaign, and the clearance shelf: both are just
                         product rows whose source is a dashboard screen. --}}
                    @case('featured_deal')
                    @case('clearance_sale')
                        @php
                            $cardCart = (bool) ($s['add_to_cart'] ?? true);
                            $offerStyle = $s['style'] ?? 'rail';
                            $offerRail = $offerStyle !== 'grid';
                        @endphp
                            <div class="ml-sec-head ml-reveal">
                                <div>
                                    <span class="ml-eyebrow">{{ $s['eyebrow'] ?: ($type === 'featured_deal' ? translate('featured_deal') : translate('clearance_sale')) }}</span>
                                    @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                </div>
                            </div>
                            <div class="{{ $offerRail ? 'ml-rail' : 'ml-grid' }} ml-reveal">
                                @foreach ($offerProducts as $product)
                                    @if ($offerRail)
                                        @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                    @else
                                        <div data-delay="{{ $loop->index % 6 }}">
                                            @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @break

                    {{-- Live coupons as cards the customer copies with one tap. Only codes anyone
                         can actually redeem are listed (see SectionDataResolver::coupons). --}}
                    @case('coupon_strip')
                            @if (!empty($s['title']) || !empty($s['eyebrow']))
                                <div class="ml-sec-head ml-reveal">
                                    <div>
                                        @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                        @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                    </div>
                                </div>
                            @endif
                            @php $couponStyle = $s['style'] ?? 'tickets'; @endphp
                            <div class="{{ $couponStyle === 'strip' ? 'ml-coupon-strip' : 'ml-grid' }} ml-coupons--{{ $couponStyle }}">
                                @foreach ($coupons as $coupon)
                                    <div class="ml-coupon ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                        <div class="ml-coupon__value">
                                            @if ($coupon->coupon_type === 'free_delivery')
                                                {{ translate('free_delivery') }}
                                            @elseif ($coupon->discount_type === 'percent')
                                                {{ (int) $coupon->discount }}%
                                            @else
                                                {{ webCurrencyConverter(amount: $coupon->discount) }}
                                            @endif
                                        </div>
                                        <div class="ml-coupon__body">
                                            <b>{{ $coupon->title }}</b>
                                            @if ($coupon->min_purchase > 0)
                                                <small>{{ translate('minimum_purchase') }}: {{ webCurrencyConverter(amount: $coupon->min_purchase) }}</small>
                                            @endif
                                            <small>{{ translate('valid_until') }} {{ \Carbon\Carbon::parse($coupon->expire_date)->translatedFormat('d M Y') }}</small>
                                        </div>
                                        <button type="button" class="ml-coupon__code" data-ml-copy="{{ $coupon->code }}"
                                                title="{{ translate('copy_code') }}">
                                            <span>{{ $coupon->code }}</span>
                                            <i class="fi fi-rr-copy"></i>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @break

                    {{-- Store stats. Every number is a real row count unless the merchant typed a
                         custom one, and they count up once when the bar scrolls into view. --}}
                    @case('stats_bar')
                        @php
                            $stats = $__data->storeStats();
                            $statBlocks = $rawBlocks;
                            $statsDark = ($s['style'] ?? 'boxed') === 'dark';
                            $countUp = (bool) ($s['animate'] ?? true);
                        @endphp
                        @if (count($statBlocks))
                            @if (!empty($s['title']) || !empty($s['eyebrow']))
                                <div class="ml-sec-head ml-sec-head--center ml-reveal">
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                    <div class="ml-rule"></div>
                                </div>
                            @endif
                            <div class="ml-grid {{ $statsDark ? 'ml-usp-dark' : '' }}">
                                @foreach ($statBlocks as $statBlock)
                                    @php
                                        $stat = $statBlock['settings'] ?? [];
                                        $statSource = $stat['source'] ?? 'products';
                                        $statValue = $statSource === 'custom'
                                            ? ($stat['value'] ?? '')
                                            : ($stats[$statSource] ?? 0);
                                    @endphp
                                    <div class="ml-stat ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                        <span class="ml-stat__icon">
                                            @include('theme-sections.partials.usp-icon', ['icon' => $stat['icon'] ?? 'shipping'])
                                        </span>
                                        <b @if ($countUp && is_numeric($statValue)) data-ml-count="{{ (int) $statValue }}" @endif>
                                            {{ is_numeric($statValue) ? number_format((int) $statValue) : $statValue }}{{ $stat['suffix'] ?? '' }}
                                        </b>
                                        <span class="ml-stat__label">{{ $stat['label'] ?? '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @break

                    {{-- Buy the set: the picked products, what the set costs after the bundle
                         discount, and one button that adds every one of them to the cart. --}}
                    @case('bundle')
                            <div class="ml-bundle ml-reveal">
                                <div class="ml-bundle__head">
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    <h2>{{ $s['title'] ?: translate('buy_the_set') }}</h2>
                                    @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                                </div>
                                <div class="ml-bundle__items">
                                    @foreach ($set['products'] as $product)
                                        {{-- One form per product, shaped exactly like the card's so the bundle
                                             button can reuse the storefront's own cart endpoint. --}}
                                        <form class="ml-bundle__form d-none">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $product->id }}">
                                            <input type="hidden" name="quantity" value="{{ max(1, (int) $product->minimum_order_qty) }}">
                                        </form>
                                        <a class="ml-bundle__item" href="{{ route('product', $product->slug) }}">
                                            <img src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'product') }}"
                                                 alt="{{ $product->name }}" loading="lazy">
                                            <span>{{ Str::limit($product->name, 34) }}</span>
                                        </a>
                                        @if (!$loop->last)<span class="ml-bundle__plus">+</span>@endif
                                    @endforeach
                                </div>
                                <div class="ml-bundle__foot">
                                    <div class="ml-bundle__price">
                                        <b>{{ webCurrencyConverter(amount: $set['discounted']) }}</b>
                                        @if ($set['saved'] > 0)
                                            <del>{{ webCurrencyConverter(amount: $set['total']) }}</del>
                                            <span class="ml-off">-{{ $set['percent'] }}%</span>
                                        @endif
                                    </div>
                                    <button type="button" class="ml-btn ml-btn-gold" data-ml-bundle
                                            data-busy="{{ translate('adding') }}...">
                                        {{ $s['button_text'] ?: translate('add_the_set_to_cart') }}
                                    </button>
                                </div>
                            </div>
                        @break

                    {{-- Shop by interest: big tiles that land on a ready-made filtered page. --}}
                    @case('interest_tiles')
                        @if (count($blocks))
                            @if (!empty($s['title']) || !empty($s['eyebrow']))
                                <div class="ml-sec-head ml-reveal">
                                    <div>
                                        @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                        @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                    </div>
                                </div>
                            @endif
                            @php $interestStyle = $s['style'] ?? 'tiles'; @endphp
                            <div class="{{ $interestStyle === 'rail' ? 'ml-rail' : ($interestStyle === 'circles' ? 'ml-cat-chips ml-interests--circles' : 'ml-grid') }}">
                                @foreach ($blocks as $tile)
                                    @php $tileOverlay = max(0, min(90, (int) ($tile['overlay'] ?? 35))) / 100; @endphp
                                    <a class="ml-tile ml-interest ml-interest--{{ $interestStyle }} ml-reveal" data-delay="{{ $loop->index % 6 }}"
                                       href="{{ $tile['link'] ?: 'javascript:void(0)' }}"
                                       style="color:{{ $tile['text_color'] ?: '#fff' }};min-height:var(--tb-h,260px)">
                                        <img src="{{ $tile['image'] ?: $__placeholder }}" alt="{{ $tile['title'] ?? '' }}" loading="lazy">
                                        <span class="ml-tile__scrim" style="background:linear-gradient(180deg,rgba(0,0,0,{{ $tileOverlay / 3 }}),rgba(0,0,0,{{ $tileOverlay }}))"></span>
                                        <span class="ml-tile__body">
                                            @if (!empty($tile['eyebrow']))<span class="ml-eyebrow" style="color:inherit;opacity:.85">{{ $tile['eyebrow'] }}</span>@endif
                                            @if (!empty($tile['title']))<h4>{{ $tile['title'] }}</h4>@endif
                                            @if (!empty($tile['subtitle']))<p>{{ $tile['subtitle'] }}</p>@endif
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                        @break

                    {{-- Stories: vertical cards that open full screen, each linking somewhere. --}}
                    @case('stories')
                        @if (count($blocks))
                            @if (!empty($s['title']) || !empty($s['eyebrow']))
                                <div class="ml-sec-head ml-reveal">
                                    <div>
                                        @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                        @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                    </div>
                                </div>
                            @endif
                            @php $storyStyle = $s['style'] ?? 'bubbles'; @endphp
                            {{-- Bubbles are the social-media shape everyone already knows how to
                                 tap; cards give the title room when the story is editorial. --}}
                            <div class="ml-stories ml-stories--{{ $storyStyle }} ml-reveal">
                                @foreach ($rawBlocks as $storyIndex => $storyBlock)
                                    @php $story = $storyBlock['settings'] ?? []; @endphp
                                    @if (!empty($story['image']) || !empty($story['video']))
                                        <button type="button" class="ml-story-dot" data-ml-story="{{ $storyIndex }}">
                                            <span class="ml-story-dot__ring">
                                                <img src="{{ $story['image'] ?: $__placeholder }}" alt="{{ $story['title'] ?? '' }}" loading="lazy">
                                            </span>
                                            <small>{{ Str::limit($story['title'] ?? '', 14) }}</small>
                                        </button>
                                    @endif
                                @endforeach
                            </div>

                            <div class="ml-story-viewer" hidden>
                                <button type="button" class="ml-story-viewer__close" aria-label="{{ translate('close') }}">&times;</button>
                                <div class="ml-story-viewer__stage">
                                    @foreach ($rawBlocks as $storyIndex => $storyBlock)
                                        @php $story = $storyBlock['settings'] ?? []; @endphp
                                        @if (!empty($story['image']) || !empty($story['video']))
                                            <figure class="ml-story-slide" data-ml-story-slide="{{ $storyIndex }}" hidden>
                                                @if (!empty($story['video']))
                                                    <video src="{{ $story['video'] }}" playsinline muted loop controls></video>
                                                @else
                                                    <img src="{{ $story['image'] }}" alt="{{ $story['title'] ?? '' }}">
                                                @endif
                                                <figcaption>
                                                    @if (!empty($story['title']))<b>{{ $story['title'] }}</b>@endif
                                                    @if (!empty($story['link']))
                                                        <a class="ml-btn ml-btn-gold" href="{{ $story['link'] }}">{{ $story['button_text'] ?: translate('shop_now') }}</a>
                                                    @endif
                                                </figcaption>
                                            </figure>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @break

                    {{-- The newest posts from the Blog module; nothing renders when it is off. --}}
                    @case('blog_posts')
                            <div class="ml-sec-head ml-reveal">
                                <div>
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    <h2>{{ $s['title'] ?: translate('from_the_blog') }}</h2>
                                </div>
                                @if (($s['view_all'] ?? true) && \Illuminate\Support\Facades\Route::has('frontend.blog.index'))
                                    <a class="ml-viewall" href="{{ route('frontend.blog.index') }}">{{ translate('view_all') }}</a>
                                @endif
                            </div>
                            @php $postStyle = $s['style'] ?? 'cards'; @endphp
                            {{-- Cards for a magazine look, list when the headline is the thing and
                                 the images are weak, featured when the newest post deserves the
                                 room and the rest are a reading list beside it. --}}
                            <div class="{{ $postStyle === 'cards' ? 'ml-grid' : 'ml-posts--' . $postStyle }}">
                                @foreach ($posts as $post)
                                    @php
                                        $postUrl = \Illuminate\Support\Facades\Route::has('frontend.blog.details') && $post->slug
                                            ? route('frontend.blog.details', ['slug' => $post->slug])
                                            : url('/');
                                    @endphp
                                    <a class="ml-post ml-reveal {{ $postStyle === 'featured' && $loop->first ? 'is-lead' : '' }}"
                                       data-delay="{{ $loop->index % 6 }}" href="{{ $postUrl }}">
                                        <span class="ml-post__thumb">
                                            <img src="{{ getStorageImages(path: $post->thumbnail_full_url, type: 'blog') }}"
                                                 alt="{{ $post->title }}" loading="lazy">
                                        </span>
                                        <span class="ml-post__body">
                                            <small>{{ \Carbon\Carbon::parse($post->publish_date)->translatedFormat('d M Y') }}</small>
                                            <b>{{ Str::limit($post->title, 70) }}</b>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        @break

                    {{-- Branches: where the shop physically is, when it opens, how to get there. --}}
                    @case('branches')
                        @if (count($rawBlocks))
                            @if (!empty($s['title']) || !empty($s['eyebrow']))
                                <div class="ml-sec-head ml-reveal">
                                    <div>
                                        @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                        @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                    </div>
                                </div>
                            @endif
                            @php $branchStyle = $s['style'] ?? 'cards'; @endphp
                            {{-- A pharmacy with three branches wants cards; one with twenty wants a
                                 list it can scan for the nearest name. --}}
                            <div class="{{ $branchStyle === 'list' ? 'ml-branches--list' : 'ml-grid' }}">
                                @foreach ($rawBlocks as $branchBlock)
                                    @php $branch = $branchBlock['settings'] ?? []; @endphp
                                    @if (!empty($branch['title']))
                                        <article class="ml-branch ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                            <h4>{{ $branch['title'] }}</h4>
                                            @if (!empty($branch['address']))
                                                <p><i class="fi fi-rr-marker"></i>{{ $branch['address'] }}</p>
                                            @endif
                                            @if (!empty($branch['hours']))
                                                <p><i class="fi fi-rr-clock"></i>{{ $branch['hours'] }}</p>
                                            @endif
                                            @if (!empty($branch['phone']))
                                                <p><i class="fi fi-rr-phone-call"></i><a class="direction-ltr" href="tel:{{ $branch['phone'] }}">{{ $branch['phone'] }}</a></p>
                                            @endif
                                            @if (!empty($branch['link']))
                                                <a class="ml-btn ml-btn-light" href="{{ $branch['link'] }}" target="_blank" rel="noopener">{{ translate('open_in_maps') }}</a>
                                            @endif
                                        </article>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @break

                    {{-- "Order within X to have it shipped today", counting down to the merchant's
                         own cut-off time. After the cut-off the section hides itself. --}}
                    @case('shipping_cutoff')
                            <div class="ml-cutoff {{ ($s['style'] ?? 'strip') === 'card' ? 'is-card' : '' }} ml-reveal"
                                 data-ml-countdown="{{ now()->addSeconds($secondsLeft)->getTimestamp() }}">
                                <i class="fi fi-rr-truck-side"></i>
                                <div>
                                    <b>{{ $s['title'] ?: translate('order_within') }}
                                        <span class="ml-cutoff__clock">
                                            <span data-unit="hours">00</span>:<span data-unit="minutes">00</span>:<span data-unit="seconds">00</span>
                                        </span>
                                    </b>
                                    <small>{{ $s['subtitle'] ?: translate('to_have_it_shipped_today') }}</small>
                                </div>
                            </div>
                        @break

                    {{-- Before / after: a slider the customer drags across two photos. --}}
                    @case('before_after')
                        @if (count($rawBlocks))
                            @if (!empty($s['title']) || !empty($s['eyebrow']))
                                <div class="ml-sec-head ml-sec-head--center ml-reveal">
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                    <div class="ml-rule"></div>
                                </div>
                            @endif
                            <div class="ml-grid">
                                @foreach ($rawBlocks as $pairBlock)
                                    @php $pair = $pairBlock['settings'] ?? []; @endphp
                                    @if (!empty($pair['image']) && !empty($pair['after']))
                                        <figure class="ml-ba ml-reveal" data-delay="{{ $loop->index % 6 }}"
                                                style="height:var(--tb-h,360px)">
                                            <img class="ml-ba__before" src="{{ $pair['image'] }}" alt="{{ translate('before') }}" loading="lazy">
                                            <span class="ml-ba__after" style="width:50%">
                                                <img src="{{ $pair['after'] }}" alt="{{ translate('after') }}" loading="lazy">
                                            </span>
                                            <input type="range" min="0" max="100" value="50" class="ml-ba__range"
                                                   aria-label="{{ translate('before_and_after') }}">
                                            <span class="ml-ba__tag ml-ba__tag--before">{{ translate('before') }}</span>
                                            <span class="ml-ba__tag ml-ba__tag--after">{{ translate('after') }}</span>
                                            @if (!empty($pair['title']) || !empty($pair['caption']))
                                                <figcaption>
                                                    @if (!empty($pair['title']))<b>{{ $pair['title'] }}</b>@endif
                                                    @if (!empty($pair['caption']))<span>{{ $pair['caption'] }}</span>@endif
                                                </figcaption>
                                            @endif
                                        </figure>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @break

                    {{-- The marketplace's sellers. A shop card carries what a buyer weighs before
                         entering a store: its cover, its logo, its rating and how much it sells. --}}
                    @case('vendor_slider')
                        @php
                            $vendorStyle = $s['style'] ?? 'cards';
                            $showStats = (bool) ($s['stats'] ?? true);
                        @endphp
                        <div class="ml-sec-head ml-reveal">
                            <div>
                                @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                <h2>{{ $s['title'] ?: translate('our_vendors') }}</h2>
                            </div>
                            @if (($s['view_all'] ?? true) && \Illuminate\Support\Facades\Route::has('vendors'))
                                <a class="ml-viewall" href="{{ route('vendors') }}">{{ translate('view_all') }}</a>
                            @endif
                        </div>

                        <div class="{{ $vendorStyle === 'rail' ? 'ml-rail ml-vendor-rail' : 'ml-grid' }} ml-reveal">
                            @foreach ($vendors as $shop)
                                @include('theme-sections.partials.vendor-card', [
                                    'shop' => $shop, 'compact' => $vendorStyle === 'compact', 'stats' => $showStats,
                                ])
                            @endforeach
                        </div>
                        @break

                    {{-- One shop, featured: its cover and logo, what its buyers rate it, and the
                         products it is selling right now. --}}
                    @case('vendor_showcase')
                        @php
                            $shop = $vendorShowcase['shop'];
                            $shopProducts = $vendorShowcase['products'];
                            $cardCart = (bool) ($s['add_to_cart'] ?? true);
                            $shopStyle = $s['style'] ?? 'rail';
                            $shopRail = $shopStyle !== 'grid';
                            $shopUrl = \Illuminate\Support\Facades\Route::has('vendor-shop') && $shop->slug
                                ? route('vendor-shop', ['slug' => $shop->slug])
                                : route('products', ['seller_id' => $shop->seller_id]);
                        @endphp

                        <div class="ml-shop ml-reveal">
                            @if ($s['cover'] ?? true)
                                <a class="ml-shop__cover" href="{{ $shopUrl }}">
                                    <img src="{{ getStorageImages(path: $shop->banner_full_url, type: 'shop-banner') }}"
                                         alt="{{ $shop->name }}" loading="lazy">
                                </a>
                            @endif
                            <div class="ml-shop__bar">
                                <a class="ml-shop__logo" href="{{ $shopUrl }}">
                                    <img src="{{ getStorageImages(path: $shop->image_full_url, type: 'shop') }}" alt="{{ $shop->name }}">
                                </a>
                                <div class="ml-shop__id">
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    <h3>{{ $s['title'] ?: $shop->name }}</h3>
                                    @if ($s['stats'] ?? true)
                                        <div class="ml-shop__stats">
                                            @if ($shop->average_rating > 0)
                                                <span><i class="fa fa-star"></i> {{ number_format($shop->average_rating, 1) }}
                                                    <small>({{ $shop->review_count }})</small></span>
                                            @endif
                                            <span>{{ $shop->products_count }} {{ translate('products') }}</span>
                                            @if ($shop->is_vacation_mode_now ?? false)
                                                <span class="ml-shop__closed">{{ translate('closed_now') }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                @if ($s['view_all'] ?? true)
                                    <a class="ml-btn ml-btn-gold ml-shop__visit" href="{{ $shopUrl }}">{{ translate('visit_store') }}</a>
                                @endif
                            </div>
                        </div>

                        @if ($shopProducts->isNotEmpty())
                            @if ($shopRail)
                                <div class="ml-rail ml-reveal">
                                    @foreach ($shopProducts as $product)
                                        @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                    @endforeach
                                </div>
                            @else
                                <div class="ml-grid">
                                    @foreach ($shopProducts as $product)
                                        <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                            @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                        @break

                    {{-- Real approved product reviews — the merchant chooses how many and the
                         minimum rating; nothing is invented. --}}
                    @case('testimonials')
                        @php $reviews = $__data->testimonials((int) ($s['limit'] ?? 3), (int) ($s['min_rating'] ?? 4)); @endphp
                        @if ($reviews->isNotEmpty())
                            <div class="ml-sec-head ml-sec-head--center ml-reveal">
                                <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('customer_voices') }}</span>
                                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                <div class="ml-rule"></div>
                            </div>
                            @php $quoteStyle = $s['style'] ?? 'cards'; @endphp
                            {{-- Cards read as endorsements, the wall reads as volume ("hundreds of
                                 people said this"), and compact fits under a product row without
                                 taking the page over. --}}
                            <div class="ml-quotes ml-quotes--{{ $quoteStyle }}">
                                @foreach ($reviews as $review)
                                    @php
                                        $reviewer = trim(($review->customer->f_name ?? '') . ' ' . ($review->customer->l_name ?? ''));
                                        $reviewer = $reviewer !== '' ? $reviewer : translate('a_customer');
                                    @endphp
                                    <article class="ml-quote ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                        <div class="ml-quote__mark">&#10078;</div>
                                        <div class="ml-quote__stars">{{ str_repeat('★', max(1, min(5, (int) $review->rating))) }}</div>
                                        <p>{{ Str::limit($review->comment, 150) }}</p>
                                        <div class="ml-quote__who">
                                            <span class="ml-quote__avatar">{{ mb_substr($reviewer, 0, 1) }}</span>
                                            <div>
                                                <b>{{ $reviewer }}</b>
                                                <small>{{ $review->product?->name ? Str::limit($review->product->name, 32) : translate('verified_customer') }}</small>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                        @break

                    {{-- FAQ: a help panel beside native <details> accordions (works without JS). --}}
                    @case('faq')
                        @php $faqStyle = $s['style'] ?? 'panel'; @endphp
                        {{-- Panel puts the questions beside a help panel; two_column splits them
                             into two readable columns for a long list; cards makes each question a
                             tile, which suits a short set of high-intent questions (delivery,
                             returns, prescriptions). --}}
                        @if (count($blocks))
                            <div class="ml-faq ml-faq--{{ $faqStyle }}">
                                <aside class="ml-faq__intro ml-reveal">
                                    <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('help_center') }}</span>
                                    <h3>{{ $s['title'] ?: translate('frequently_asked_questions') }}</h3>
                                    @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                                    @if (!empty($s['button_text']))
                                        <a href="{{ $s['link'] ?: route('contacts') }}" class="ml-btn ml-btn-light">{{ $s['button_text'] }}</a>
                                    @endif
                                </aside>
                                <div class="ml-reveal ml-faq__list">
                                    @foreach ($__section['blocks'] ?? [] as $qa)
                                        @php $qaSettings = $qa['settings'] ?? []; @endphp
                                        @if (!empty($qaSettings['question']))
                                            <details @if ($loop->first && $faqStyle === 'panel') open @endif>
                                                <summary><span>{{ $qaSettings['question'] }}</span></summary>
                                                <div>{{ $qaSettings['answer'] ?? '' }}</div>
                                            </details>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @break

                    {{-- Three ways to present the same brands, chosen in the builder:
                         marquee (continuous logo strip), grid (bordered logo cards), or
                         story (large gradient cards, one per brand). Every card links to the
                         brand's own landing page, which carries its banner and category chips. --}}
                    @case('brand_slider')
                        @php
                            $brands = $__data->brands((int) ($s['limit'] ?? 12));
                            $brandStyle = $s['style'] ?? 'marquee';
                            $brandUrl = fn ($brand) => \Illuminate\Support\Facades\Route::has('brand-products') && $brand->slug
                                ? route('brand-products', ['slug' => $brand->slug])
                                : route('products', ['brand_id' => $brand->id]);
                        @endphp
                        @if ($brands->isNotEmpty())
                            <div class="ml-sec-head ml-reveal">
                                <div>
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                </div>
                                @if (\Illuminate\Support\Facades\Route::has('brands'))
                                    <a class="ml-viewall" href="{{ route('brands') }}">{{ translate('all_brands') }}</a>
                                @endif
                            </div>

                            @if ($brandStyle === 'story')
                                <div class="ml-story">
                                    @foreach ($brands->take(6) as $brand)
                                        <a class="ml-storycard ml-reveal" data-delay="{{ $loop->index % 6 }}" href="{{ $brandUrl($brand) }}">
                                            <img src="{{ getStorageImages(path: $brand->image_full_url, type: 'brand') }}" alt="{{ $brand->name }}" loading="lazy">
                                            <h3>{{ $brand->name }}</h3>
                                            <span>{{ translate('shop_the_brand') }} &#8592;</span>
                                        </a>
                                    @endforeach
                                </div>
                            @elseif ($brandStyle === 'grid')
                                <div class="ml-brandgrid ml-reveal">
                                    @foreach ($brands as $brand)
                                        <a class="ml-brandcard" href="{{ $brandUrl($brand) }}">
                                            <img src="{{ getStorageImages(path: $brand->image_full_url, type: 'brand') }}" alt="{{ $brand->name }}" loading="lazy">
                                            {{ $brand->name }}
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <div class="ml-marquee ml-reveal">
                                    <div class="ml-marquee__track">
                                        @foreach ($brands->concat($brands) as $brand)
                                            <a href="{{ $brandUrl($brand) }}" class="ml-brand" aria-hidden="{{ $loop->index >= $brands->count() ? 'true' : 'false' }}">
                                                <img src="{{ getStorageImages(path: $brand->image_full_url, type: 'brand') }}" alt="{{ $brand->name }}" loading="lazy">
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif
                        @break

                    @case('promotional_banner')
                        {{-- Tiles sit still, 'rail' scrolls when there are more banners than fit,
                             and 'overlap' staggers them so the row reads as a composition rather
                             than a table. The partial takes the style and does the rest. --}}
                        @include('theme-sections.partials.banner-grid', [
                            'cards' => $blocks, 'settings' => $s, 'placeholder' => $__placeholder,
                            'columns' => max(1, (int) ($s['columns'] ?? 2)), 'gap' => $gap,
                            'style' => $s['style'] ?? 'tiles',
                        ])
                        @break

                    @case('split_banner')
                        @include('theme-sections.partials.banner-split', [
                            'cards' => $blocks, 'settings' => $s, 'placeholder' => $__placeholder, 'gap' => $gap,
                        ])
                        @break

                    @case('banner_mosaic')
                        @include('theme-sections.partials.banner-mosaic', [
                            'cards' => $blocks, 'settings' => $s, 'placeholder' => $__placeholder, 'gap' => $gap,
                        ])
                        @break

                    @case('banner_strip')
                        @include('theme-sections.partials.banner-strip', [
                            'card' => [
                                'image' => $s['image'] ?? null, 'eyebrow' => $s['eyebrow'] ?? null,
                                'title' => $s['title'] ?? null, 'subtitle' => $s['subtitle'] ?? null,
                                'link' => $s['link'] ?? null, 'button_text' => $s['button_text'] ?? null,
                            ],
                            'settings' => $s, 'placeholder' => $__placeholder,
                        ])
                        @break

                    {{-- Banners created in Promotion -> Banners, rendered in whichever presentation
                         the merchant picked. This is what makes a dashboard banner show up in the theme. --}}
                    @case('store_banner')
                        @php
                            $cards = $__data->dashboardBanners((string) ($s['banner_type'] ?? 'Main Banner'), (int) ($s['limit'] ?? 6));
                            $layout = (string) ($s['layout'] ?? 'carousel');
                        @endphp
                        @if (count($cards))
                            @if (!empty($s['title']) || !empty($s['subtitle']))
                                <div class="ml-sec-head ml-reveal">
                                    @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                    <div class="ml-rule"></div>
                                    @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                                </div>
                            @endif

                            @if ($layout === 'carousel')
                                @include('theme-sections.partials.hero', ['slides' => $cards, 'settings' => $s, 'index' => 'sb' . $loop->index, 'placeholder' => $__placeholder])
                            @elseif ($layout === 'mosaic')
                                @include('theme-sections.partials.banner-mosaic', ['cards' => $cards, 'settings' => $s, 'placeholder' => $__placeholder, 'gap' => $gap])
                            @elseif ($layout === 'split')
                                @include('theme-sections.partials.banner-split', ['cards' => $cards, 'settings' => $s, 'placeholder' => $__placeholder, 'gap' => $gap])
                            @elseif ($layout === 'strip')
                                @include('theme-sections.partials.banner-strip', ['card' => $cards[0], 'settings' => $s, 'placeholder' => $__placeholder])
                            @else
                                {{-- 'grid' and 'swipe' share the banner-grid partial, which reads
                                     the style; grid is also the safe landing for a value that is
                                     no longer offered. --}}
                                @include('theme-sections.partials.banner-grid', [
                                    'cards' => $cards, 'settings' => $s, 'placeholder' => $__placeholder,
                                    'columns' => max(1, (int) ($s['columns'] ?? 3)), 'gap' => $gap,
                                    'style' => $layout === 'swipe' ? 'swipe' : 'tiles',
                                ])
                            @endif
                        @endif
                        @break

                    {{-- Trust badges in two skins: light boxed cards, or the dark panel from the
                         mockup ("display style" in the builder). --}}
                    @case('usp_strip')
                        @php
                            $uspStyle = $s['style'] ?? 'boxed';
                            $boxed = $uspStyle !== 'plain';
                        @endphp
                        @if (count($blocks))
                            <div class="ml-grid {{ $uspStyle === 'dark' ? 'ml-usp-dark' : '' }}">
                                @foreach ($blocks as $card)
                                    <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                        <a class="ml-usp {{ $boxed ? 'is-boxed' : '' }}" href="{{ $card['link'] ?: 'javascript:void(0)' }}">
                                            <span class="ml-usp__icon">
                                                @if (!empty($card['image']))
                                                    <img src="{{ $card['image'] }}" alt="" width="22" height="22" loading="lazy">
                                                @else
                                                    @include('theme-sections.partials.usp-icon', ['icon' => $card['icon'] ?? 'shipping'])
                                                @endif
                                            </span>
                                            <span>
                                                <strong>{{ $card['title'] }}</strong>
                                                <span>{{ $card['subtitle'] }}</span>
                                            </span>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @break

                    {{-- Panel is a block of its own, inline is a single line that sits between two
                         sections without interrupting them, split puts the promise beside the field. --}}
                    @case('newsletter')
                        @php $newsStyle = $s['style'] ?? 'panel'; @endphp
                        <div class="ml-news ml-news--{{ $newsStyle }} ml-reveal">
                            @if (!empty($s['title']))<h3>{{ $s['title'] }}</h3>@endif
                            @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                            <form class="ml-news-form" onsubmit="return false;">
                                <input type="email" placeholder="{{ translate('your_email_address') }}" aria-label="{{ translate('email') }}">
                                <button type="submit" class="ml-btn ml-btn-gold">{{ translate('subscribe') }}</button>
                            </form>
                        </div>
                        @break

                    @case('custom_html')
                        <div class="ml-reveal">{{ $s['content'] ?? '' }}</div>
                        @break

                    {{-- One row, several sources, one line of tabs. Everything is rendered up
                         front and switched with a class, so changing tab costs no request and works
                         with JavaScript disabled (the first tab is simply the one that shows). --}}
                    @case('product_tabs')
                        @php
                            $tabsId = 'ml-tabs-' . ($__section['id'] ?? $loop->index);
                            $tabStyle = $s['style'] ?? 'rail';
                            $cardCart = (bool) ($s['add_to_cart'] ?? true);
                            $tabs = [];
                            foreach ($__section['blocks'] ?? [] as $tabBlock) {
                                $tabSettings = $tabBlock['settings'] ?? [];
                                if (empty($tabSettings['label'])) { continue; }
                                $tabs[] = [
                                    'label' => $tabSettings['label'],
                                    'products' => $__data->products($tabSettings + ['limit' => (int) ($s['limit'] ?? 8)]),
                                ];
                            }
                            $tabs = array_values(array_filter($tabs, fn ($tab) => $tab['products']->isNotEmpty()));
                        @endphp
                        @if ($tabs !== [])
                            <div class="ml-sec-head ml-reveal">
                                <div>
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                </div>
                                <div class="ml-tabs" data-ml-tabs="{{ $tabsId }}">
                                    @foreach ($tabs as $tabIndex => $tab)
                                        <button type="button" class="ml-tabs__btn {{ $tabIndex === 0 ? 'is-active' : '' }}"
                                                data-ml-tab="{{ $tabIndex }}">{{ $tab['label'] }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @foreach ($tabs as $tabIndex => $tab)
                                <div class="ml-tabs__panel {{ $tabIndex === 0 ? 'is-active' : '' }}"
                                     data-ml-tab-panel="{{ $tabsId }}-{{ $tabIndex }}" data-ml-tab-of="{{ $tabsId }}">
                                    <div class="{{ $tabStyle === 'grid' ? 'ml-grid' : 'ml-rail' }}">
                                        @foreach ($tab['products'] as $product)
                                            @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        @endif
                        @break

                    {{-- The brand half of category_showcase: one brand, its mark, its products. --}}
                    @case('brand_showcase')
                        @php
                            $cardCart = (bool) ($s['add_to_cart'] ?? true);
                            $brandRail = ($s['style'] ?? 'rail') !== 'grid';
                        @endphp
                        <div class="ml-sec-head ml-reveal">
                            <div class="d-flex align-items-center gap-3">
                                @if (($s['logo'] ?? true) && !empty($brandShowcase['brand']->image_full_url))
                                    <span class="ml-brand-mark">
                                        <img src="{{ getStorageImages(path: $brandShowcase['brand']->image_full_url, type: 'brand') }}"
                                             alt="{{ $brandShowcase['brand']->name }}" loading="lazy">
                                    </span>
                                @endif
                                <div>
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    <h2>{{ $s['title'] ?: $brandShowcase['brand']->name }}</h2>
                                </div>
                            </div>
                            @if ($s['view_all'] ?? true)
                                <a class="ml-viewall" href="{{ route('products', ['brand_id' => $brandShowcase['brand']->id]) }}">{{ translate('view_all') }}</a>
                            @endif
                        </div>
                        <div class="{{ $brandRail ? 'ml-rail' : 'ml-grid' }} ml-reveal">
                            @foreach ($brandShowcase['products'] as $product)
                                @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                            @endforeach
                        </div>
                        @break

                    {{-- What customers actually typed into the search box. Not a hand-written list
                         of what the merchant hopes is popular — the shop's own measured demand,
                         already filtered of bots and staff by the analytics rollup. --}}
                    @case('trending_searches')
                        @php $termStyle = $s['style'] ?? 'chips'; @endphp
                        @if (!empty($s['title']) || !empty($s['eyebrow']))
                            <div class="ml-sec-head ml-reveal">
                                <div>
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                </div>
                            </div>
                        @endif
                        @if ($termStyle === 'ranked')
                            <ol class="ml-trend ml-reveal">
                                @foreach ($searchTerms as $term)
                                    <li>
                                        <a href="{{ route('products', ['search' => $term->term]) }}">
                                            <span class="ml-trend__rank">{{ $loop->iteration }}</span>
                                            <span class="ml-trend__term">{{ $term->term }}</span>
                                            <span class="ml-trend__count">{{ number_format((int) $term->searches) }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ol>
                        @else
                            <div class="ml-cat-chips ml-reveal">
                                @foreach ($searchTerms as $term)
                                    <a class="ml-chip" href="{{ route('products', ['search' => $term->term]) }}">
                                        <span>{{ $term->term }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                        @break

                    {{-- A visitor's own history, shown back to them. Scoped to their own first-party
                         cookie — reading anyone else's would be both wrong and a privacy failure. --}}
                    @case('recently_viewed')
                        @php
                            $cardCart = (bool) ($s['add_to_cart'] ?? true);
                            $seenRail = ($s['style'] ?? 'rail') !== 'grid';
                        @endphp
                        <div class="ml-sec-head ml-reveal">
                            <div>
                                @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                <h2>{{ $s['title'] ?: translate('recently_viewed') }}</h2>
                            </div>
                        </div>
                        <div class="{{ $seenRail ? 'ml-rail' : 'ml-grid' }} ml-reveal">
                            @foreach ($seenProducts as $product)
                                @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                            @endforeach
                        </div>
                        @break

                    {{-- Get the app. The QR is generated by the same service the campaign builder
                         uses, over the same deep link, so the code on a poster and the code on this
                         page open the same thing. --}}
                    @case('app_download')
                        @php
                            $appStyle = $s['style'] ?? 'split';
                            $androidUrl = $appStores['android'] ?? null;
                            $iosUrl = $appStores['ios'] ?? null;
                            $qrSvg = ($s['qr'] ?? true)
                                ? app(\App\Services\Analytics\Support\QrCode::class)->svg(url('/'), 132)
                                : null;
                        @endphp
                            <div class="ml-getapp ml-getapp--{{ $appStyle }} ml-reveal">
                                <div class="ml-getapp__copy">
                                    @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                    <h3>{{ $s['title'] ?: translate('get_the_app') }}</h3>
                                    @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                                    <div class="ml-getapp__badges">
                                        @if ($androidUrl)
                                            <a class="ml-btn ml-btn-gold" href="{{ $androidUrl }}" rel="noopener">{{ translate('google_play') }}</a>
                                        @endif
                                        @if ($iosUrl)
                                            <a class="ml-btn ml-btn-light" href="{{ $iosUrl }}" rel="noopener">{{ translate('app_store') }}</a>
                                        @endif
                                    </div>
                                </div>
                                @if ($qrSvg)
                                    <div class="ml-getapp__qr">{!! $qrSvg !!}</div>
                                @elseif (!empty($s['image']))
                                    <div class="ml-getapp__art"><img src="{{ $s['image'] }}" alt="" loading="lazy"></div>
                                @endif
                            </div>
                        @break

                    {{-- Shop by what the customer can spend, which is how a lot of people actually
                         shop — and the one entry point a category tree cannot offer. --}}
                    @case('price_tiles')
                        @if (count($blocks))
                            @if (!empty($s['title']) || !empty($s['eyebrow']))
                                <div class="ml-sec-head ml-reveal">
                                    <div>
                                        @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                        @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                    </div>
                                </div>
                            @endif
                            <div class="ml-grid">
                                @foreach ($__section['blocks'] ?? [] as $bandBlock)
                                    @php
                                        // Its own settings, not the banner-shaped card blockCards()
                                        // builds: a price band is a number pair, not a picture.
                                        $band = $bandBlock['settings'] ?? [];
                                        $min = max(0, (int) ($band['min'] ?? 0));
                                        $max = max(0, (int) ($band['max'] ?? 0));
                                        $query = ['min_price' => $min] + ($max > 0 ? ['max_price' => $max] : []);
                                        $label = ($band['label'] ?? '') ?: ($max > 0
                                            ? webCurrencyConverter(amount: $min) . ' - ' . webCurrencyConverter(amount: $max)
                                            : webCurrencyConverter(amount: $min) . '+');
                                    @endphp
                                    <a class="ml-price-tile ml-reveal" data-delay="{{ $loop->index % 6 }}"
                                       href="{{ route('products', $query) }}"
                                       @if (!empty($band['image'])) style="background-image:linear-gradient(140deg,rgba(20,8,46,.62),rgba(20,8,46,.28)),url('{{ $band['image'] }}')" @endif>
                                        <span class="ml-price-tile__label">{{ $label }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                        @break

                    @case('spacer')
                        <div style="height:var(--tb-h,{{ (int) ($s['height'] ?? 40) }}px)"></div>
                        @break

                @endswitch
            </div>
        </section>
    @endforeach
</div>

<script>
    "use strict";
    (function () {
        var root = document.querySelector('.ml-sections');
        if (!root) return;
        var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Reveal on scroll. Without IntersectionObserver everything is shown immediately, so the
        // content is never hidden behind a capability the browser lacks.
        var revealables = root.querySelectorAll('.ml-reveal');
        if (calm || !('IntersectionObserver' in window)) {
            revealables.forEach(function (el) { el.classList.add('is-in'); });
        } else {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-in');
                    observer.unobserve(entry.target);
                });
            }, {rootMargin: '0px 0px -8% 0px', threshold: 0.08});
            revealables.forEach(function (el) { observer.observe(el); });
        }

        // Hero sliders — self-contained so the section does not depend on the theme's slider library.
        root.querySelectorAll('[data-ml-slider]').forEach(function (slider) {
            var slides = Array.prototype.slice.call(slider.querySelectorAll('.ml-hero__slide'));
            if (slides.length < 2) return;

            var dots = Array.prototype.slice.call(slider.querySelectorAll('.ml-hero__dots button'));
            var interval = parseInt(slider.dataset.interval, 10) || 5000;
            var autoplay = slider.dataset.autoplay === '1' && !calm;
            var current = 0;
            var timer = null;

            function show(next) {
                current = (next + slides.length) % slides.length;
                slides.forEach(function (slide, i) { slide.classList.toggle('is-active', i === current); });
                dots.forEach(function (dot, i) { dot.classList.toggle('is-active', i === current); });
            }
            function play() { if (autoplay) { stop(); timer = setInterval(function () { show(current + 1); }, Math.max(2000, interval)); } }
            function stop() { if (timer) { clearInterval(timer); timer = null; } }

            slider.querySelectorAll('[data-ml-slide]').forEach(function (button) {
                button.addEventListener('click', function () {
                    show(current + (button.dataset.mlSlide === 'next' ? 1 : -1));
                    play();
                });
            });
            dots.forEach(function (dot, i) { dot.addEventListener('click', function () { show(i); play(); }); });
            slider.addEventListener('mouseenter', stop);
            slider.addEventListener('mouseleave', play);

            var startX = null;
            slider.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, {passive: true});
            slider.addEventListener('touchend', function (e) {
                if (startX === null) return;
                var delta = e.changedTouches[0].clientX - startX;
                if (Math.abs(delta) > 45) show(current + (delta < 0 ? 1 : -1));
                startX = null;
            });

            show(0);
            play();
        });

        // Product rails: the arrow controls scroll one "page" of cards, direction-aware so the
        // buttons feel right in Arabic (RTL) too.
        function railStep(rail) { return Math.max(240, rail.clientWidth * 0.8); }
        function railScroll(rail, direction) {
            var rtl = getComputedStyle(rail).direction === 'rtl';
            var step = railStep(rail) * direction;
            rail.scrollBy({left: rtl ? -step : step, behavior: 'smooth'});
        }

        // Product tabs: every panel is already in the page, so switching is a class change rather
        // than a request — and with JavaScript off the first tab is simply the one that shows.
        root.querySelectorAll('[data-ml-tabs]').forEach(function (bar) {
            bar.addEventListener('click', function (event) {
                var button = event.target.closest('[data-ml-tab]');
                if (!button) return;

                var group = bar.dataset.mlTabs;
                bar.querySelectorAll('[data-ml-tab]').forEach(function (item) { item.classList.remove('is-active'); });
                button.classList.add('is-active');

                root.querySelectorAll('[data-ml-tab-of="' + group + '"]').forEach(function (panel) {
                    panel.classList.toggle('is-active', panel.dataset.mlTabPanel === group + '-' + button.dataset.mlTab);
                });
            });
        });

        root.querySelectorAll('[data-ml-rail]').forEach(function (button) {
            button.addEventListener('click', function () {
                var rail = document.getElementById(button.dataset.mlRail);
                if (rail) railScroll(rail, parseInt(button.dataset.dir, 10));
            });
        });

        // Pagination dots for a rail: one dot per scrolled "page", kept in sync while the
        // customer scrolls by hand, and clickable to jump. Only drawn when the builder's
        // "pagination" option is on for that section.
        root.querySelectorAll('[data-ml-rail-dots]').forEach(function (host) {
            var rail = document.getElementById(host.dataset.mlRailDots);
            if (!rail) return;

            var dots = [];
            function pages() { return Math.max(1, Math.ceil(rail.scrollWidth / Math.max(1, rail.clientWidth))); }
            function currentPage() {
                return Math.round(Math.abs(rail.scrollLeft) / Math.max(1, rail.clientWidth));
            }
            function paint() {
                var active = currentPage();
                dots.forEach(function (dot, i) { dot.classList.toggle('is-active', i === active); });
            }
            function build() {
                var total = pages();
                host.innerHTML = '';
                dots = [];
                if (total < 2) return;
                for (var i = 0; i < total; i++) {
                    (function (index) {
                        var dot = document.createElement('button');
                        dot.type = 'button';
                        dot.setAttribute('aria-label', String(index + 1));
                        dot.addEventListener('click', function () {
                            var rtl = getComputedStyle(rail).direction === 'rtl';
                            var target = index * rail.clientWidth;
                            rail.scrollTo({left: rtl ? -target : target, behavior: 'smooth'});
                        });
                        host.appendChild(dot);
                        dots.push(dot);
                    })(i);
                }
                paint();
            }

            rail.addEventListener('scroll', function () { window.requestAnimationFrame(paint); }, {passive: true});
            window.addEventListener('resize', build);
            build();
        });

        // Rail autoplay: advances a page at the builder's interval, pauses on hover/touch and
        // whenever the tab is hidden, and wraps back to the start at the end.
        root.querySelectorAll('[data-ml-rail-auto]').forEach(function (rail) {
            if (calm) return;
            var every = Math.max(2000, parseInt(rail.dataset.mlRailAuto, 10) || 4000);
            var timer = null;

            function atEnd() {
                return Math.abs(rail.scrollLeft) + rail.clientWidth >= rail.scrollWidth - 8;
            }
            function advance() {
                if (atEnd()) {
                    rail.scrollTo({left: 0, behavior: 'smooth'});
                    return;
                }
                railScroll(rail, 1);
            }
            function play() { stop(); timer = setInterval(advance, every); }
            function stop() { if (timer) { clearInterval(timer); timer = null; } }

            rail.addEventListener('mouseenter', stop);
            rail.addEventListener('mouseleave', play);
            rail.addEventListener('touchstart', stop, {passive: true});
            document.addEventListener('visibilitychange', function () {
                document.hidden ? stop() : play();
            });
            play();
        });

        // Flash-deal countdown: ticks against the REAL end date of the running deal (a unix
        // timestamp rendered server-side), and stops itself the moment the deal expires.
        root.querySelectorAll('[data-ml-countdown]').forEach(function (box) {
            var endsAt = parseInt(box.dataset.mlCountdown, 10) * 1000;
            if (!endsAt) return;
            var units = {};
            box.querySelectorAll('[data-unit]').forEach(function (node) { units[node.dataset.unit] = node; });

            function pad(value) { return String(Math.max(0, value)).padStart(2, '0'); }
            function tick() {
                var left = Math.max(0, endsAt - Date.now());
                var seconds = Math.floor(left / 1000);
                if (units.days) units.days.textContent = pad(Math.floor(seconds / 86400));
                if (units.hours) units.hours.textContent = pad(Math.floor((seconds % 86400) / 3600));
                if (units.minutes) units.minutes.textContent = pad(Math.floor((seconds % 3600) / 60));
                if (units.seconds) units.seconds.textContent = pad(seconds % 60);
                if (left <= 0) clearInterval(timer);
            }
            tick();
            var timer = setInterval(tick, 1000);
        });

        // Parallax strips: transform only, driven by rAF, and skipped entirely for reduced motion.
        var strips = Array.prototype.slice.call(root.querySelectorAll('.ml-strip.is-parallax'));
        if (strips.length && !calm) {
            var ticking = false;
            var move = function () {
                strips.forEach(function (strip) {
                    var box = strip.getBoundingClientRect();
                    if (box.bottom < 0 || box.top > window.innerHeight) return;
                    var progress = (box.top + box.height / 2 - window.innerHeight / 2) / window.innerHeight;
                    var layer = strip.querySelector('.ml-strip__bg');
                    if (layer) layer.style.transform = 'translate3d(0,' + (progress * -28).toFixed(2) + 'px,0)';
                });
                ticking = false;
            };
            window.addEventListener('scroll', function () {
                if (ticking) return;
                ticking = true;
                window.requestAnimationFrame(move);
            }, {passive: true});
            move();
        }
        // Coupon cards: one tap puts the code on the clipboard, and the button says so.
        root.querySelectorAll('[data-ml-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var code = button.dataset.mlCopy || '';
                var done = function () {
                    button.classList.add('is-copied');
                    setTimeout(function () { button.classList.remove('is-copied'); }, 1600);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(code).then(done).catch(done);
                    return;
                }
                // http:// storefronts have no clipboard API — fall back to a hidden field.
                var field = document.createElement('textarea');
                field.value = code;
                field.setAttribute('readonly', 'readonly');
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(field);
                done();
            });
        });

        // Store stats count up once, when the bar first scrolls into view.
        var counters = Array.prototype.slice.call(root.querySelectorAll('[data-ml-count]'));
        if (counters.length) {
            var format = function (value) { return value.toLocaleString(); };
            var run = function (node) {
                var target = parseInt(node.dataset.mlCount, 10) || 0;
                // The server prints the value and the merchant's suffix ("+", "K") in one node.
                var suffix = (node.textContent.trim().match(/[^\d.,\s]+$/) || [''])[0];
                if (calm || target <= 0) { node.textContent = format(target) + suffix; return; }
                var started = null;
                var step = function (now) {
                    if (started === null) started = now;
                    var progress = Math.min(1, (now - started) / 1200);
                    var eased = 1 - Math.pow(1 - progress, 3);
                    node.textContent = format(Math.round(target * eased)) + suffix;
                    if (progress < 1) window.requestAnimationFrame(step);
                };
                window.requestAnimationFrame(step);
            };

            if ('IntersectionObserver' in window) {
                var countWatcher = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (!entry.isIntersecting) return;
                        countWatcher.unobserve(entry.target);
                        run(entry.target);
                    });
                }, {threshold: 0.4});
                counters.forEach(function (node) { countWatcher.observe(node); });
            } else {
                counters.forEach(run);
            }
        }

        // "Add the set to cart": posts each product through the storefront's own cart endpoint,
        // one after the other so the cart lines land in order, then refreshes the nav cart once.
        root.querySelectorAll('[data-ml-bundle]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.dataset.pending === '1') return;
                var bundle = button.closest('.ml-bundle');
                var forms = bundle ? Array.prototype.slice.call(bundle.querySelectorAll('.ml-bundle__form')) : [];
                var endpoint = document.querySelector('#route-cart-add');
                if (!forms.length || !endpoint || typeof window.jQuery === 'undefined') return;

                var $ = window.jQuery;
                var url = $(endpoint).data('url');
                var label = button.innerHTML;
                button.dataset.pending = '1';
                button.innerHTML = button.dataset.busy || label;

                var addNext = function (index) {
                    if (index >= forms.length) {
                        button.dataset.pending = '0';
                        button.innerHTML = label;
                        if (typeof window.updateNavCart === 'function') {
                            window.updateNavCart(function () {
                                if (typeof window.openCartDrawer === 'function') window.openCartDrawer();
                            });
                        }
                        return;
                    }
                    $.post({
                        url: url,
                        headers: {'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')},
                        data: $(forms[index]).serializeArray().concat({name: 'buy_now', value: 0}),
                        complete: function () { addNext(index + 1); }
                    });
                };
                addNext(0);
            });
        });

        // Story viewer: tap a ring to open the story full screen; arrows, swipe and Escape move on.
        root.querySelectorAll('.ml-story-viewer').forEach(function (viewer) {
            var section = viewer.closest('.tbs') || root;
            var dots = Array.prototype.slice.call(section.querySelectorAll('[data-ml-story]'));
            var slides = Array.prototype.slice.call(viewer.querySelectorAll('[data-ml-story-slide]'));
            if (!dots.length || !slides.length) return;

            var order = slides.map(function (slide) { return slide.dataset.mlStorySlide; });
            var current = 0;

            function paint() {
                slides.forEach(function (slide, i) {
                    slide.hidden = i !== current;
                    var video = slide.querySelector('video');
                    if (!video) return;
                    if (i === current) { video.play().catch(function () {}); } else { video.pause(); }
                });
            }
            function open(key) {
                var index = order.indexOf(String(key));
                current = index < 0 ? 0 : index;
                viewer.hidden = false;
                document.body.style.overflow = 'hidden';
                paint();
            }
            function close() {
                viewer.hidden = true;
                document.body.style.overflow = '';
                slides.forEach(function (slide) {
                    var video = slide.querySelector('video');
                    if (video) video.pause();
                });
            }
            function move(step) {
                current += step;
                if (current < 0 || current >= slides.length) { close(); return; }
                paint();
            }

            dots.forEach(function (dot) {
                dot.addEventListener('click', function () { open(dot.dataset.mlStory); });
            });
            viewer.querySelector('.ml-story-viewer__close').addEventListener('click', close);
            viewer.addEventListener('click', function (event) {
                if (event.target === viewer) close();
            });
            document.addEventListener('keydown', function (event) {
                if (viewer.hidden) return;
                if (event.key === 'Escape') close();
                if (event.key === 'ArrowRight') move(1);
                if (event.key === 'ArrowLeft') move(-1);
            });

            var storyStartX = null;
            viewer.addEventListener('touchstart', function (e) { storyStartX = e.touches[0].clientX; }, {passive: true});
            viewer.addEventListener('touchend', function (e) {
                if (storyStartX === null) return;
                var delta = e.changedTouches[0].clientX - storyStartX;
                if (Math.abs(delta) > 45) move(delta < 0 ? 1 : -1);
                storyStartX = null;
            });
        });

        // Before / after: the range input IS the handle, so keyboard and pointer both work and the
        // reveal is a single custom property the CSS reads.
        root.querySelectorAll('.ml-ba').forEach(function (figure) {
            var range = figure.querySelector('.ml-ba__range');
            var after = figure.querySelector('.ml-ba__after');
            if (!range || !after) return;

            var paint = function () {
                var value = Math.max(0, Math.min(100, parseFloat(range.value) || 0));
                after.style.width = value + '%';
                figure.style.setProperty('--ml-ba', value + '%');
                // The clipped strip narrows as the handle moves; its photo must not narrow with it.
                figure.style.setProperty('--ml-ba-w', figure.clientWidth + 'px');
            };
            range.addEventListener('input', paint);
            window.addEventListener('resize', paint);
            paint();
        });

        // Multi-frame mosaic tiles: crossfade through the frames in place, at the section's own
        // interval. Paused while the tab is hidden — a background tab must not burn the cycle.
        document.querySelectorAll('.ml-tile--frames').forEach(function (tile) {
            var frames = tile.querySelectorAll('.ml-tile__frame');
            if (frames.length < 2) return;
            var at = 0;
            setInterval(function () {
                if (document.hidden) return;
                frames[at].classList.remove('is-on');
                at = (at + 1) % frames.length;
                frames[at].classList.add('is-on');
            }, parseInt(tile.dataset.rotate, 10) || 4000);
        });

    })();
</script>
@endif

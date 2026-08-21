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
    $__placeholder = dynamicAsset(path: 'public/assets/front-end/img/image-place-holder.png');
    // Types this file can draw. A section whose type has no renderer here is skipped entirely
    // rather than emitting an empty padded <section>, which reads on the page as a broken gap.
    $__renderable = ['hero_banner', 'category_grid', 'product_slider', 'brand_slider', 'promotional_banner',
        'split_banner', 'banner_mosaic', 'banner_strip', 'store_banner', 'usp_strip', 'newsletter',
        'custom_html', 'spacer', 'flash_deal', 'testimonials', 'faq', 'category_showcase',
        'vendor_slider', 'vendor_showcase'];
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
    .ml-cat-name{ font-size:.78rem; font-weight:600; color:var(--ml-ink2); margin-top:.55rem; min-height:0; }

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
    .ml-mosaic{ display:grid; grid-template-columns:repeat(4,1fr); }
    @media (max-width:767px){ .ml-mosaic{ grid-template-columns:repeat(2,1fr) } .ml-tile--large,.ml-tile--wide{ grid-column:span 2 } }

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
        @endphp

        @continue(($s['visible'] ?? true) === false || !in_array($type, $__renderable, true))
        @continue($type === 'flash_deal' && !$deal)
        @continue($type === 'category_showcase' && !$showcase)
        @continue($type === 'vendor_slider' && $vendors->isEmpty())
        @continue($type === 'vendor_showcase' && !$vendorShowcase)

        @if ($breakpointCss)<style>{!! $breakpointCss !!}</style>@endif
        <section id="{{ $sectionKey }}" class="tbs tbs-{{ $type }} tbs-align-{{ $align }}" style="{{ $wrapStyle }}"
                 data-tb-section="{{ $__section['id'] ?? '' }}">
            <div class="{{ $full ? 'container-fluid px-0' : 'container' }}">
                @switch($type)

                    @case('hero_banner')
                        @include('theme-sections.partials.hero', ['slides' => $blocks, 'settings' => $s, 'index' => $loop->index, 'placeholder' => $__placeholder])
                        @break

                    @case('category_grid')
                        @php $cats = $__data->categories(limit: (int) ($s['limit'] ?? 12), picked: $s['category_ids'] ?? null); @endphp
                        @if ($cats->isNotEmpty())
                            <div class="ml-sec-head ml-reveal">
                                <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('shop_by_category') }}</span>
                                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                <div class="ml-rule"></div>
                            </div>
                            <div class="ml-grid">
                                @foreach ($cats as $cat)
                                    <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                        <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-cat">
                                            <span class="ml-cat-ring">
                                                @if ($cat->icon)
                                                    <img src="{{ getStorageImages(path: $cat->icon_full_url, type: 'category') }}" alt="{{ $cat->name }}" loading="lazy">
                                                @endif
                                            </span>
                                            <span class="ml-name ml-cat-name">{{ $cat->name }}</span>
                                        </a>
                                    </div>
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
                            $isRail = ($s['style'] ?? 'rail') === 'rail';
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

                            @if ($isRail)
                                <div class="ml-rail ml-reveal" id="{{ $railId }}"
                                     @if ($railAutoplay) data-ml-rail-auto="{{ $railInterval }}" @endif>
                                    @foreach ($products as $product)
                                        @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                                    @endforeach
                                </div>
                                @if ($showDots)
                                    <div class="ml-rail-dots" data-ml-rail-dots="{{ $railId }}"></div>
                                @endif
                            @else
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
                        @endphp
                        @if ($deal)
                            <div class="ml-flash ml-reveal">
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
                                <div class="ml-rail ml-reveal mt-3" id="{{ $dealRailId }}">
                                    @foreach ($dealProducts as $product)
                                        @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
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
                            $showcaseRail = ($s['style'] ?? 'rail') === 'rail';
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
                            $shopRail = ($s['style'] ?? 'rail') === 'rail';
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
                            <div class="ml-quotes">
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
                        @if (count($blocks))
                            <div class="ml-faq">
                                <aside class="ml-faq__intro ml-reveal">
                                    <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('help_center') }}</span>
                                    <h3>{{ $s['title'] ?: translate('frequently_asked_questions') }}</h3>
                                    @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                                    @if (!empty($s['button_text']))
                                        <a href="{{ $s['link'] ?: route('contacts') }}" class="ml-btn ml-btn-light">{{ $s['button_text'] }}</a>
                                    @endif
                                </aside>
                                <div class="ml-reveal">
                                    @foreach ($__section['blocks'] ?? [] as $qa)
                                        @php $qaSettings = $qa['settings'] ?? []; @endphp
                                        @if (!empty($qaSettings['question']))
                                            <details @if ($loop->first) open @endif>
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
                        @include('theme-sections.partials.banner-grid', [
                            'cards' => $blocks, 'settings' => $s, 'placeholder' => $__placeholder,
                            'columns' => max(1, (int) ($s['columns'] ?? 2)), 'gap' => $gap,
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
                                @include('theme-sections.partials.banner-grid', [
                                    'cards' => $cards, 'settings' => $s, 'placeholder' => $__placeholder,
                                    'columns' => max(1, (int) ($s['columns'] ?? 3)), 'gap' => $gap,
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

                    @case('newsletter')
                        <div class="ml-news ml-reveal">
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
    })();
</script>
@endif

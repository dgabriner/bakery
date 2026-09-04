<?php
/** Shared visual system for the SF Baker sourdough journal. */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}
?>
<style>
  /* SF Baker: a warmer, more tactile layer over the customer portal shell. */
  .sfb-body {
    background:
      radial-gradient(circle at 8% 14%, rgba(225, 183, 104, .14), transparent 24rem),
      linear-gradient(135deg, #fffdf8 0%, #f7efe5 100%);
  }
  .sfb-body .portal-top { background: rgba(255, 253, 248, .92); backdrop-filter: blur(14px); }
  .sfb-body .portal-top__name { color: #4b3024; letter-spacing: .01em; }
  .sfb-app {
    --sfb-espresso: #352219;
    --sfb-cocoa: #654230;
    --sfb-ochre: #d28b45;
    --sfb-cream: #fffaf2;
    --sfb-paper: #f2e7d8;
    --sfb-line: rgba(83, 51, 32, .14);
    max-width: 920px;
    padding-bottom: 28px;
  }
  .sfb-tabs {
    align-items: center;
    background: var(--sfb-espresso);
    border: 1px solid rgba(53, 34, 25, .15);
    border-radius: 18px;
    box-shadow: 0 12px 26px rgba(75, 45, 26, .12);
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 18px;
    padding: 6px;
  }
  .sfb-tabs a {
    border: 0;
    border-radius: 12px;
    color: rgba(255, 250, 242, .7);
    flex: 1 1 112px;
    font-size: .78rem;
    font-weight: 650;
    letter-spacing: .025em;
    min-height: var(--sf-touch-min, 44px);
    padding: 10px 12px;
    text-align: center;
    text-decoration: none;
    transition: background .18s ease, color .18s ease, transform .18s ease;
  }
  .sfb-tabs a:hover { color: #fffaf2; transform: translateY(-1px); }
  .sfb-tabs a.active {
    background: #f9eddc;
    box-shadow: 0 2px 5px rgba(0, 0, 0, .12);
    color: var(--sfb-espresso);
    font-weight: 750;
  }
  .sfb-app .card, .sfb-app .delivery-card {
    background: rgba(255, 253, 248, .92);
    border-color: var(--sfb-line);
    border-radius: 18px;
    box-shadow: 0 10px 24px rgba(88, 57, 36, .055);
  }
  .sfb-app .card-header {
    align-items: center;
    background: linear-gradient(90deg, rgba(246, 235, 220, .9), rgba(255, 253, 248, .25));
    border-bottom-color: var(--sfb-line);
    display: flex;
    min-height: 58px;
    padding: 15px 18px;
  }
  .sfb-app .card-header h2, .sfb-app .card h2 {
    color: var(--sfb-espresso);
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 1.08rem;
    letter-spacing: -.01em;
  }
  .sfb-app .card-body { padding: 18px; }
  .sfb-app .add-row { border-top-color: var(--sfb-line); padding: 16px 18px; }
  .sfb-app .section-title {
    color: var(--sfb-espresso);
    font-size: 1.24rem;
    letter-spacing: -.015em;
    margin: 22px 0 12px;
  }
  .sfb-app .btn {
    background: linear-gradient(135deg, #be613f, #9b432e);
    border-radius: 12px;
    box-shadow: 0 5px 12px rgba(142, 61, 38, .16);
    letter-spacing: .01em;
    transition: box-shadow .18s ease, transform .18s ease;
  }
  .sfb-app .btn:hover { box-shadow: 0 8px 16px rgba(142, 61, 38, .25); transform: translateY(-1px); }
  .sfb-app .btn-secondary {
    background: #fffaf2;
    border-color: rgba(83, 51, 32, .22);
    box-shadow: none;
    color: var(--sfb-espresso);
  }
  .sfb-app .btn-link { color: #a44831; font-weight: 650; }
  .sfb-app .notice { border-radius: 14px; box-shadow: 0 6px 16px rgba(83, 51, 32, .05); }
  .sfb-app .line-list { margin-bottom: 4px; }
  .sfb-app .line-list li { border-bottom-color: var(--sfb-line); padding: 13px 0; }
  .sfb-app .line-list li:first-child { padding-top: 0; }
  .sfb-app .line-qty, .sfb-app .sfb-grams, .sfb-app .sfb-ratio { color: #a44831; }
  .sfb-app .badge { font-size: .68rem; letter-spacing: .045em; padding: 5px 9px; text-transform: uppercase; }
  .sfb-app .badge-info { background: #f6e9d6; color: #805333; }
  .sfb-app .badge-ok { background: #e2f0e7; color: #236044; }
  .sfb-app .badge-muted { background: #ede7e1; color: #756960; }
  .sfb-field label > span, .sfb-app .inline-form label > span {
    color: #805f4c;
    font-size: .7rem;
    font-weight: 750;
    letter-spacing: .07em;
    text-transform: uppercase;
  }
  .sfb-tip {
    align-items: center;
    background: #ead6bd;
    border-radius: 999px;
    color: #6f472e;
    cursor: help;
    display: inline-flex !important;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: .63rem !important;
    font-weight: 800;
    height: 15px;
    justify-content: center;
    letter-spacing: 0 !important;
    line-height: 1;
    margin-left: 4px;
    position: relative;
    text-transform: none !important;
    vertical-align: 1px;
    width: 15px;
  }
  .sfb-tip::after {
    background: var(--sfb-espresso);
    border-radius: 9px;
    bottom: calc(100% + 8px);
    box-shadow: 0 8px 18px rgba(53, 34, 25, .2);
    color: #fffaf2;
    content: attr(data-tooltip);
    font-size: .75rem;
    font-weight: 500;
    left: 0;
    letter-spacing: 0;
    line-height: 1.35;
    opacity: 0;
    padding: 8px 10px;
    pointer-events: none;
    position: absolute;
    text-transform: none;
    transform: translateY(3px);
    transition: opacity .15s ease, transform .15s ease;
    visibility: hidden;
    width: min(220px, 62vw);
    z-index: 10;
  }
  .sfb-tip:hover::after, .sfb-tip:focus-visible::after { opacity: 1; transform: translateY(0); visibility: visible; }
  .sfb-tip:focus-visible { box-shadow: 0 0 0 3px rgba(210, 139, 69, .3); outline: 0; }
  .sfb-field input, .sfb-field select, .sfb-field textarea,
  .sfb-app .inline-form input, .sfb-app .inline-form textarea, .sfb-app .inline-form select,
  .sfb-pct-input {
    background: #fffdf9;
    border-color: rgba(83, 51, 32, .18);
    border-radius: 11px;
    color: var(--sfb-espresso);
  }
  .sfb-field input:focus, .sfb-field select:focus, .sfb-field textarea:focus,
  .sfb-app .inline-form input:focus, .sfb-app .inline-form textarea:focus, .sfb-app .inline-form select:focus,
  .sfb-pct-input:focus {
    border-color: var(--sfb-ochre);
    box-shadow: 0 0 0 3px rgba(210, 139, 69, .17);
    outline: 0;
  }
  .sfb-grid2 { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
  .sfb-grid3 { display: grid; gap: 12px; grid-template-columns: repeat(3, 1fr); }
  .sfb-pct-input { font-size: 1rem; height: 44px; padding: 6px 10px; text-align: right; width: 90px; }
  .sfb-line-forms, .sfb-feeding__top { align-items: center; display: flex; gap: 8px; }
  .sfb-feeding__top { justify-content: space-between; }

  /* Dashboard */
  .sfb-hero {
    background-color: var(--sfb-espresso);
    background-image:
      linear-gradient(90deg, rgba(33, 20, 14, .92) 0%, rgba(33, 20, 14, .77) 38%, rgba(33, 20, 14, .25) 69%, rgba(33, 20, 14, .08) 100%),
      url('assets/sfb-sourdough-hero.png');
    background-position: center, 69% center;
    background-size: cover;
    border-color: transparent !important;
    box-shadow: 0 16px 30px rgba(53, 34, 25, .22) !important;
    min-height: 268px;
    position: relative;
  }
  .sfb-hero .card-body { display: flex; flex-direction: column; justify-content: center; min-height: 268px; max-width: 500px; position: relative; }
  .sfb-hero .hero-label { color: #f0c897; font-size: .7rem; font-weight: 750; letter-spacing: .14em; margin-bottom: 10px; }
  .sfb-hero .sfb-journey-count { color: #fffaf2; font-family: Georgia, 'Times New Roman', serif; font-size: clamp(2.65rem, 9vw, 4.1rem); font-weight: normal; letter-spacing: -.055em; line-height: .95; }
  .sfb-hero .sfb-journey-count span { color: rgba(255, 250, 242, .75) !important; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: .9rem !important; letter-spacing: 0; }
  .sfb-journey-bar { background: rgba(255, 250, 242, .18); border: 1px solid rgba(255, 250, 242, .24); border-radius: 999px; height: 10px; margin-top: 20px; overflow: hidden; position: relative; }
  .sfb-journey-fill { background: linear-gradient(90deg, #d99052, #f2c982); border-radius: 999px; box-shadow: 0 0 16px rgba(242, 201, 130, .5); height: 100%; min-width: 0; transition: width .4s ease; }
  .sfb-milestones { display: flex; justify-content: space-between; margin-top: 8px; }
  .sfb-milestones span { color: rgba(255, 250, 242, .5); font-size: .66rem; }
  .sfb-milestones span.hit { color: #f5d8a9; }
  .sfb-hero .muted { color: rgba(255, 250, 242, .78); font-size: .9rem; margin-top: 16px !important; max-width: 310px; }
  .sfb-quick { display: grid; gap: 10px; grid-template-columns: repeat(3, 1fr); }
  .sfb-quick a {
    align-items: center;
    background: rgba(255, 253, 248, .8);
    border-color: var(--sfb-line);
    border-radius: 15px;
    box-shadow: 0 6px 14px rgba(83, 51, 32, .045);
    display: flex;
    flex-direction: column;
    font-weight: 600;
    gap: 2px;
    min-height: 92px;
    justify-content: center;
    transition: transform .18s ease, box-shadow .18s ease;
  }
  .sfb-quick a:hover { box-shadow: 0 10px 18px rgba(83, 51, 32, .11); transform: translateY(-2px); }
  .sfb-quick a strong { color: #a44831; font-family: Georgia, 'Times New Roman', serif; font-size: 1.6rem; line-height: 1; }
  .sfb-app .delivery-card { padding: 17px 18px; transition: box-shadow .18s ease, transform .18s ease; }
  .sfb-app .delivery-card:hover { box-shadow: 0 13px 24px rgba(83, 51, 32, .11); transform: translateY(-2px); }
  .sfb-app .delivery-card-date { color: var(--sfb-espresso); font-size: 1.12rem; }
  .sfb-app .delivery-card-summary { color: #806a5a; }

  /* Bake log */
  .sfb-phase { border-left: 4px solid #e7d9ca; }
  .sfb-phase.current { border-left-color: var(--sfb-ochre); box-shadow: 0 12px 28px rgba(176, 103, 49, .1) !important; }
  .sfb-timeline { display: flex; gap: 5px; margin-bottom: 16px; }
  .sfb-timeline span { background: #eee3d6; border-radius: 9px; color: #846f60; font-size: .67rem; padding: 8px 3px; }
  .sfb-timeline span.hit { background: #e0efe5; color: #2f6a4c; }
  .sfb-timeline span.now { background: linear-gradient(135deg, #bc623f, #933d2c); box-shadow: 0 4px 10px rgba(147, 61, 44, .18); }
  .sfb-photos { display: grid; gap: 10px; grid-template-columns: repeat(3, 1fr); margin-top: 10px; }
  .sfb-photo { aspect-ratio: 1; border-color: var(--sfb-line); border-radius: 12px; box-shadow: 0 3px 9px rgba(53, 34, 25, .08); display: block; object-fit: cover; width: 100%; }
  .sfb-photo-wrap { position: relative; }
  .sfb-photo-wrap form { position: absolute; right: 4px; top: 4px; }
  .sfb-photo-del { background: rgba(0, 0, 0, .55); border: 0; border-radius: 999px; color: #fff; cursor: pointer; font-size: .8rem; height: 28px; width: 28px; }
  .sfb-caption { color: var(--muted); font-size: .72rem; margin-top: 3px; }
  .sfb-turn { border-bottom-color: var(--sfb-line); padding: 12px 0; }
  .sfb-feeding { border-bottom-color: var(--sfb-line); padding: 13px 0; }

  /* Batch discussion */
  .sfb-discussion__intro { margin: 4px 0 0; }
  .sfb-message-list { display: grid; gap: 10px; }
  .sfb-message {
    background: #fffaf4;
    border: 1px solid var(--sfb-line);
    border-radius: 13px;
    padding: 12px;
  }
  .sfb-message--admin { background: #f0f7f2; border-color: #cfe3d4; }
  .sfb-message--reply { margin-left: 18px; }
  .sfb-message__meta { align-items: center; display: flex; flex-wrap: wrap; gap: 5px 7px; }
  .sfb-message__meta strong { color: var(--sfb-espresso); font-size: .84rem; }
  .sfb-message__role, .sfb-message__meta time { color: #806a5a; font-size: .72rem; }
  .sfb-message__type {
    background: #f6e9d6;
    border-radius: 999px;
    color: #805333;
    font-size: .65rem;
    font-weight: 750;
    letter-spacing: .04em;
    padding: 3px 7px;
    text-transform: uppercase;
  }
  .sfb-message__type.is-resolved { background: #dceee2; color: #236044; }
  .sfb-message__body { color: var(--sfb-espresso); font-size: .9rem; line-height: 1.48; margin: 8px 0 0; }
  .sfb-discussion__composer { border-top: 1px solid var(--sfb-line); margin-top: 18px; padding-top: 16px; }

  /* Resources and community: a public-facing view intentionally separate from the private bake log. */
  .sfb-resource-hero, .sfb-community-hero, .sfb-shared-batch-hero {
    background: linear-gradient(135deg, #4b3024, #785039);
    border-color: transparent !important;
    color: #fffaf2;
  }
  .sfb-resource-hero h2, .sfb-community-hero h2, .sfb-shared-batch-hero h2 { color: #fffaf2 !important; font-size: clamp(1.65rem, 5vw, 2.35rem) !important; }
  .sfb-resource-hero .hero-label, .sfb-community-hero .hero-label, .sfb-shared-batch-hero .hero-label { color: #f0c897; }
  .sfb-resource-hero .muted, .sfb-community-hero .muted, .sfb-shared-batch-hero .muted { color: rgba(255, 250, 242, .76); }
  .sfb-resource-grid { display: grid; gap: 14px; margin-top: 14px; }
  .sfb-resource-card h2 { margin-top: 0; }
  .sfb-resource-card__lead { color: #684a3b; font-weight: 650; line-height: 1.45; }
  .sfb-resource-card__circle {
    color: #805333;
    font-size: .68rem;
    font-weight: 750;
    letter-spacing: .06em;
    margin: 0 0 6px;
    text-transform: uppercase;
  }
  .sfb-resource-card__next {
    background: #f8f0e6;
    border-left: 4px solid #c68a59;
    border-radius: 0 10px 10px 0;
    color: #4b3024;
    line-height: 1.45;
    margin: 12px 0 0;
    padding: 8px 10px;
  }
  .sfb-resource-card--trouble { border-color: #e0c4a4; }
  .sfb-resource-card ul { color: #604436; line-height: 1.52; margin: 12px 0 0; padding-left: 1.2rem; }
  .sfb-resource-card li + li { margin-top: 8px; }
  .sfb-resource-sources { margin-top: 14px; }
  .sfb-resource-sources a { color: #9b432e; font-weight: 650; }
  .sfb-resource-action { margin-top: 14px; }
  .sfb-app .section-title { margin: 22px 0 8px; }

  .sfb-disclosure, .sfb-community-disclosure {
    background: rgba(255, 250, 242, .12);
    border: 1px solid rgba(255, 250, 242, .28);
    border-radius: 12px;
    color: #fffaf2;
    font-size: .88rem;
    line-height: 1.45;
    margin: 14px 0 0;
    padding: 10px 12px;
  }
  .sfb-library-strip { margin-top: 4px; }
  .sfb-library-strip .hero-label { margin: 0 0 10px; }
  .sfb-library-strip__list { display: grid; gap: 8px; list-style: none; margin: 0; padding: 0; }
  .sfb-library-strip__list > li > a:not(.sfb-library-strip__ask) {
    background: #f9f1e7;
    border-radius: 12px;
    color: var(--sfb-espresso);
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 10px 12px;
    text-decoration: none;
  }
  .sfb-library-strip__list > li > a:not(.sfb-library-strip__ask):hover { background: #f3e6d4; }
  .sfb-library-strip__list span { color: #806a5a; font-size: .78rem; line-height: 1.4; }
  .sfb-library-strip__ask {
    background: transparent;
    color: var(--sfb-espresso);
    display: inline-block;
    font-size: .78rem;
    font-weight: 700;
    margin: 4px 0 0 4px;
    padding: 0;
    text-decoration: underline;
  }
  .sfb-human-loaves { color: #fffaf2; font-weight: 650; margin: 12px 0 0; }
  .sfb-process-hint { color: #806a5a; font-size: .82rem; line-height: 1.4; margin: 8px 0 0; }
  .sfb-process-hint.is-warn { color: #9b432e; font-weight: 650; }
  .sfb-resource-card .btn-row { margin-top: 14px; }
  .sfb-library-panel .hero-label { margin: 0 0 6px; }
  .sfb-review-list { margin: 10px 0 0; padding-left: 1.2rem; }
  .sfb-review-list li { margin: 0 0 8px; }
  .sfb-review-list a { color: var(--sfb-espresso); font-weight: 650; }
  .sfb-diagnose-chips { display: flex; flex-wrap: wrap; gap: 8px; list-style: none; margin: 10px 0 0; padding: 0; }
  .sfb-diagnose-chips li {
    align-items: center;
    background: #f9f1e7;
    border-radius: 999px;
    display: inline-flex;
    gap: 8px;
    padding: 6px 10px 6px 12px;
  }
  .sfb-diagnose-chips a { color: var(--sfb-espresso); font-size: .8rem; font-weight: 650; text-decoration: none; }
  .sfb-diagnose-chips .btn-link { font-size: .72rem; }
  .sfb-library-panel__suggest { color: #805333; font-size: .72rem; font-weight: 750; letter-spacing: .04em; margin: 10px 0 0; text-transform: uppercase; }

  .sfb-community-compose { margin-top: 14px; }
  .sfb-community-filters { display: flex; flex-wrap: wrap; gap: 7px; margin: 18px 0; }
  .sfb-community-filters a {
    background: rgba(255, 253, 248, .8);
    border: 1px solid var(--sfb-line);
    border-radius: 999px;
    color: #785039;
    font-size: .76rem;
    font-weight: 700;
    padding: 7px 10px;
    text-decoration: none;
  }
  .sfb-community-filters a.active { background: #785039; border-color: #785039; color: #fffaf2; }
  .sfb-community-feed, .sfb-community-replies { display: grid; gap: 12px; }
  .sfb-topic-card h3 { font-family: Georgia, 'Times New Roman', serif; font-size: 1.15rem; line-height: 1.22; margin: 8px 0; }
  .sfb-topic-card h3 a { color: var(--sfb-espresso); text-decoration: none; }
  .sfb-topic-card h3 a:hover { color: #a44831; text-decoration: underline; }
  .sfb-topic-card p, .sfb-community-reply p, .sfb-topic-detail__body { color: #594033; line-height: 1.55; }
  .sfb-topic-card__meta { align-items: center; color: #806a5a; display: flex; flex-wrap: wrap; font-size: .74rem; gap: 5px 8px; }
  .sfb-topic-card__category {
    background: #f6e9d6;
    border-radius: 999px;
    color: #805333;
    font-size: .65rem;
    font-weight: 750;
    letter-spacing: .04em;
    padding: 3px 7px;
    text-transform: uppercase;
  }
  .sfb-topic-card__batch {
    background: #f8f0e6;
    border: 1px solid #e7d3bc;
    border-radius: 12px;
    color: var(--sfb-espresso);
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 13px 0;
    padding: 10px 12px;
    text-decoration: none;
  }
  .sfb-topic-card__batch:hover { border-color: #c68a59; box-shadow: 0 4px 10px rgba(83, 51, 32, .08); }
  .sfb-topic-card__batch span, .sfb-topic-card__batch small { color: #806a5a; font-size: .71rem; }
  .sfb-topic-detail h2 { font-size: clamp(1.4rem, 5vw, 2rem) !important; margin: 12px 0; }
  .sfb-topic-detail__body { font-size: 1rem; }
  .sfb-back-link { margin: 0 0 12px; }
  .sfb-back-link a { color: #9b432e; font-size: .84rem; font-weight: 700; }

  .sfb-batch-share { border-left: 4px solid #d28b45; }
  .sfb-batch-share h2 { margin: 0 0 6px; }
  .sfb-batch-share__revoke { margin: 12px 0 0; text-align: center; }
  .sfb-batch-share__revoke .btn-link { background: transparent; border: 0; cursor: pointer; font: inherit; }
  .sfb-shared-batch-hero__formula { font-family: Georgia, 'Times New Roman', serif; font-size: 1.05rem; margin: 4px 0 14px; }
  .sfb-shared-facts { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; }
  .sfb-shared-facts div { background: #f9f1e7; border-radius: 11px; padding: 10px; }
  .sfb-shared-facts dt { color: #806a5a; font-size: .68rem; font-weight: 750; letter-spacing: .06em; text-transform: uppercase; }
  .sfb-shared-facts dd { color: var(--sfb-espresso); font-weight: 700; margin: 4px 0 0; }
  .sfb-shared-photos { display: grid; gap: 11px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .sfb-shared-photos figure { margin: 0; }
  .sfb-shared-photos img { aspect-ratio: 1; border-radius: 12px; box-shadow: 0 4px 12px rgba(53, 34, 25, .1); display: block; object-fit: cover; width: 100%; }
  .sfb-shared-photos figcaption { color: #806a5a; font-size: .74rem; margin-top: 5px; }

  .sfb-origin-badge {
    border-radius: 999px;
    display: inline-block;
    font-size: .68rem;
    font-weight: 650;
    letter-spacing: .02em;
    line-height: 1.2;
    padding: .18rem .55rem;
    vertical-align: middle;
  }
  .sfb-origin-badge--human {
    background: #e7f3ea;
    color: #215c32;
  }
  .sfb-origin-badge--synthetic {
    background: #efe8dc;
    color: #5c4630;
  }
  .sfb-origin-badge--coach {
    background: #e7eef6;
    color: #1f3d66;
  }

  .visually-hidden {
    border: 0;
    clip: rect(0 0 0 0);
    height: 1px;
    margin: -1px;
    overflow: hidden;
    padding: 0;
    position: absolute;
    width: 1px;
  }
  .sfb-community-disclosure {
    color: rgba(255, 250, 242, .88);
    font-size: .9rem;
    margin: 10px 0 0;
  }
  .sfb-community-search {
    align-items: stretch;
    display: flex;
    gap: 8px;
    margin: 16px 0 0;
  }
  .sfb-community-search label { flex: 1; margin: 0; }
  .sfb-community-search input[type="search"] {
    border: 1px solid var(--sfb-line);
    border-radius: 12px;
    font: inherit;
    padding: 10px 12px;
    width: 100%;
  }
  .sfb-activity { margin-top: 14px; }
  .sfb-activity-list { display: grid; gap: 8px; list-style: none; margin: 0; padding: 0; }
  .sfb-activity-list a {
    align-items: baseline;
    background: #f9f1e7;
    border-radius: 12px;
    color: inherit;
    display: grid;
    gap: 2px;
    padding: 10px 12px;
    text-decoration: none;
  }
  .sfb-activity-list a:hover { background: #f3e6d4; }
  .sfb-activity-list__kind {
    color: #805333;
    font-size: .65rem;
    font-weight: 750;
    letter-spacing: .04em;
    text-transform: uppercase;
  }
  .sfb-activity-list__meta {
    align-items: center;
    color: #806a5a;
    display: flex;
    flex-wrap: wrap;
    font-size: .74rem;
    gap: 6px;
  }
  .sfb-topic-card--pinned { border-color: #c68a59; }
  .sfb-topic-card__pinned {
    background: #f3d7b0;
    border-radius: 999px;
    color: #6a3d16;
    font-size: .65rem;
    font-weight: 750;
    letter-spacing: .04em;
    padding: 3px 7px;
    text-transform: uppercase;
  }
  .sfb-community-compose--coach { border-left: 4px solid #1f3d66; }
  .sfb-community-reply--coach { border-left: 4px solid #1f3d66; }
  .sfb-hero-actions { margin: 14px 0 0; }
  .sfb-filter-note { color: #806a5a; font-size: .82rem; margin: 0 0 12px; }
  .sfb-filter-note a { color: #9b432e; font-weight: 700; }
  details.sfb-community-compose { margin-top: 18px; }
  details.sfb-community-compose > summary {
    cursor: pointer;
    list-style: none;
  }
  details.sfb-community-compose > summary::-webkit-details-marker { display: none; }
  details.sfb-community-compose > summary h2 { margin: 0; }
  .sfb-inline-bake {
    background: #f8f0e6;
    border: 1px solid #e7d3bc;
    border-radius: 14px;
    margin: 16px 0 0;
    padding: 14px;
  }
  .sfb-inline-bake__label {
    color: #805333;
    font-size: .65rem;
    font-weight: 750;
    letter-spacing: .04em;
    margin: 0 0 4px;
    text-transform: uppercase;
  }
  .sfb-inline-bake h3 { font-family: Georgia, 'Times New Roman', serif; font-size: 1.1rem; margin: 0 0 6px; }
  .sfb-inline-bake__baker { align-items: center; display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 10px; }
  .sfb-inline-bake__facts { display: flex; flex-wrap: wrap; gap: 6px; list-style: none; margin: 0 0 12px; padding: 0; }
  .sfb-inline-bake__facts li {
    background: #fffdf8;
    border-radius: 999px;
    font-size: .74rem;
    font-weight: 650;
    padding: 4px 8px;
  }
  .sfb-inline-bake__photos { display: grid; gap: 8px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin: 0 0 12px; }
  .sfb-inline-bake__photos img { aspect-ratio: 1; border-radius: 10px; display: block; object-fit: cover; width: 100%; }
  .sfb-lane-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
  .sfb-lane-actions .btn { width: auto; }
  .sfb-lane-split { margin: 12px 0 0; text-align: center; }
  .sfb-lane-split a { color: #9b432e; font-weight: 700; }
  .sfb-bake-discussions { display: grid; gap: 8px; list-style: none; margin: 0; padding: 0; }
  .sfb-bake-discussions a {
    background: #f9f1e7;
    border-radius: 12px;
    color: inherit;
    display: grid;
    gap: 2px;
    padding: 10px 12px;
    text-decoration: none;
  }
  .sfb-bake-discussions a:hover { background: #f3e6d4; }
  .sfb-bake-discussions span { color: #806a5a; font-size: .78rem; }
  .sfb-bake-log { display: grid; gap: 8px; list-style: none; margin: 0; padding: 0; }
  .sfb-bake-log li {
    align-items: baseline;
    background: #f9f1e7;
    border-radius: 11px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    justify-content: space-between;
    padding: 10px 12px;
  }

  @media (min-width: 640px) {
    .sfb-app { padding-top: 24px; }
    .sfb-tabs { border-radius: 20px; gap: 6px; padding: 7px; }
    .sfb-tabs a { font-size: .82rem; }
    .sfb-hero { min-height: 304px; }
    .sfb-hero .card-body { min-height: 304px; padding: 30px; }
    .sfb-app .card-body { padding: 20px; }
    .sfb-app .card-header, .sfb-app .add-row { padding-left: 20px; padding-right: 20px; }
    .sfb-resource-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .sfb-shared-photos { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  @media (max-width: 430px) {
    .sfb-tabs a { flex-basis: calc(50% - 4px); }
    .sfb-hero { background-position: center, 62% center; }
    .sfb-hero .card-body { min-height: 250px; }
  }
</style>

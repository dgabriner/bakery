<?php
/** Shared presentation for the administrator SF Baker engagement workspace. */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}
?>
<style>
  .sfb-admin { max-width: 1220px; margin: 0 auto; padding: 24px 20px 48px; }
  .sfb-admin__header { align-items: end; background: linear-gradient(135deg, #2e211a, #613827); border-radius: 18px; color: #fffaf3; display: flex; flex-wrap: wrap; gap: 18px; justify-content: space-between; margin-bottom: 18px; padding: 26px; }
  .sfb-admin__header h1 { color: inherit; font-family: Georgia, 'Times New Roman', serif; font-size: clamp(1.75rem, 4vw, 2.4rem); font-weight: 500; margin: 0; }
  .sfb-admin__header .page-eyebrow { color: #f4c78c; margin-bottom: 4px; }
  .sfb-admin__header p:last-child { color: rgba(255,250,243,.78); margin: 7px 0 0; max-width: 650px; }
  .sfb-admin__header a { color: #fffaf3; }
  .sfb-admin__stats { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-bottom: 18px; }
  .sfb-admin__stat { background: #fff; border: 1px solid #e8ddd2; border-radius: 14px; padding: 16px; }
  .sfb-admin__stat strong { color: #3a241a; display: block; font-size: 1.7rem; font-variant-numeric: tabular-nums; line-height: 1.1; }
  .sfb-admin__stat span { color: #78675b; display: block; font-size: .78rem; font-weight: 700; letter-spacing: .035em; margin-top: 5px; text-transform: uppercase; }
  .sfb-admin__layout { align-items: start; display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); }
  .sfb-admin__panel { background: #fff; border: 1px solid #e8ddd2; border-radius: 15px; box-shadow: 0 8px 22px rgba(72, 45, 29, .05); padding: 19px; }
  .sfb-admin__panel h2 { color: #3a241a; font-family: Georgia, 'Times New Roman', serif; font-size: 1.25rem; font-weight: 500; margin-bottom: 4px; }
  .sfb-admin__panel > p { color: #78675b; font-size: .92rem; }
  .sfb-admin__notice { border-radius: 11px; margin-bottom: 18px; padding: 12px 14px; }
  .sfb-admin__notice--success { background: #e8f4ea; color: #286244; }
  .sfb-admin__notice--error { background: #fbe9e6; color: #8b342c; }
  .sfb-admin__filters, .sfb-admin__composer { display: grid; gap: 12px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .sfb-admin__composer { grid-template-columns: minmax(190px, .7fr) minmax(0, 1.6fr) auto; }
  .sfb-admin label { color: #6a5142; display: grid; font-size: .76rem; font-weight: 700; gap: 5px; letter-spacing: .04em; text-transform: uppercase; }
  .sfb-admin input, .sfb-admin select, .sfb-admin textarea { background: #fffdfa; border: 1px solid #d9c9ba; border-radius: 9px; color: #3a241a; font: inherit; letter-spacing: normal; min-height: 42px; padding: 9px 10px; text-transform: none; width: 100%; }
  .sfb-admin textarea { min-height: 82px; resize: vertical; }
  .sfb-admin input:focus, .sfb-admin select:focus, .sfb-admin textarea:focus { border-color: #b75c3f; box-shadow: 0 0 0 3px rgba(183,92,63,.15); outline: 0; }
  .sfb-admin button, .sfb-admin .sfb-admin__button { align-items: center; background: #a84d35; border: 1px solid #a84d35; border-radius: 9px; color: #fff; cursor: pointer; display: inline-flex; font: inherit; font-size: .9rem; font-weight: 700; justify-content: center; min-height: 42px; padding: 9px 14px; text-decoration: none; }
  .sfb-admin button:hover, .sfb-admin .sfb-admin__button:hover { background: #8d3d2a; border-color: #8d3d2a; color: #fff; }
  .sfb-admin .sfb-admin__button--secondary { background: #fff; border-color: #d9c9ba; color: #603b2c; }
  .sfb-admin .sfb-admin__button--secondary:hover { background: #f8eee5; color: #603b2c; }
  .sfb-admin .sfb-admin__button--quiet { background: #fff8ed; border-color: #e7c995; color: #875526; font-size: .78rem; min-height: 34px; padding: 6px 9px; }
  .sfb-admin__question-list { display: grid; gap: 12px; }
  .sfb-admin__question { background: #fffaf4; border: 1px solid #eadbc9; border-left: 4px solid #d08b42; border-radius: 11px; padding: 14px; }
  .sfb-admin__question-head, .sfb-admin__batch-head, .sfb-admin__message-head { align-items: flex-start; display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; }
  .sfb-admin__question h3, .sfb-admin__batch h3 { color: #3a241a; font-size: 1rem; margin: 0; }
  .sfb-admin__question-meta, .sfb-admin__batch-meta, .sfb-admin__message-meta { color: #78675b; font-size: .79rem; margin: 4px 0 0; }
  .sfb-admin__question-body, .sfb-admin__message-body { color: #443128; line-height: 1.52; margin: 12px 0; white-space: normal; }
  .sfb-admin__reply-form { display: grid; gap: 8px; grid-template-columns: minmax(0, 1fr) auto; }
  .sfb-admin__reply-form textarea { min-height: 58px; }
  .sfb-admin__batch-list { display: grid; gap: 12px; }
  .sfb-admin__batch { background: #fff; border: 1px solid #e8ddd2; border-radius: 12px; padding: 15px; }
  .sfb-admin__batch--active { border-left: 4px solid #c27831; }
  .sfb-admin__batch--completed { border-left: 4px solid #43835b; }
  .sfb-admin__batch--abandoned { border-left: 4px solid #8c8178; }
  .sfb-admin__pills { align-items: center; display: flex; flex-wrap: wrap; gap: 6px; }
  .sfb-admin__pill { background: #f5eadb; border-radius: 999px; color: #785330; font-size: .68rem; font-weight: 750; letter-spacing: .04em; padding: 4px 8px; text-transform: uppercase; }
  .sfb-admin__pill--ok { background: #e3f0e7; color: #276545; }
  .sfb-admin__pill--muted { background: #eee9e4; color: #70655d; }
  .sfb-admin__pill--attention { background: #fdf0d8; color: #925817; }
  .sfb-admin__batch-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; margin-top: 13px; }
  .sfb-admin__empty { background: #fffaf4; border: 1px dashed #d7c3b0; border-radius: 11px; color: #78675b; padding: 17px; }
  .sfb-admin__detail-grid { align-items: start; display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); }
  .sfb-admin__facts { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 14px 0 0; }
  .sfb-admin__fact { background: #fffaf4; border-radius: 9px; padding: 10px; }
  .sfb-admin__fact span { color: #806958; display: block; font-size: .7rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
  .sfb-admin__fact strong { color: #3a241a; display: block; font-size: .92rem; margin-top: 3px; }
  .sfb-admin__message-list { display: grid; gap: 10px; }
  .sfb-admin__message { background: #fffaf4; border: 1px solid #eadbc9; border-radius: 11px; padding: 13px; }
  .sfb-admin__message--admin { background: #eff7f1; border-color: #cfe3d4; }
  .sfb-admin__message--reply { margin-left: 22px; }
  .sfb-admin__message-meta { align-items: center; display: flex; flex-wrap: wrap; gap: 5px 7px; margin: 0; }
  .sfb-admin__message-meta strong { color: #3a241a; }
  .sfb-admin__message-meta time { color: #806958; font-size: .74rem; }
  .sfb-admin__message-body { margin-bottom: 0; }
  .sfb-admin__message-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
  .sfb-admin__message-actions form { margin: 0; }
  .sfb-admin__message-reply { border-top: 1px solid #eadbc9; margin-top: 12px; padding-top: 12px; }
  .sfb-admin__message-reply label { margin-bottom: 6px; }
  .sfb-admin__media { display: grid; gap: 9px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 14px; }
  .sfb-admin__media img { aspect-ratio: 1; border-radius: 9px; display: block; object-fit: cover; width: 100%; }
  .sfb-admin__timeline { display: grid; gap: 8px; list-style: none; margin: 0; padding: 0; }
  .sfb-admin__timeline li { border-bottom: 1px solid #eee1d4; color: #5f493b; padding: 9px 0; }
  .sfb-admin__timeline li:last-child { border-bottom: 0; }
  .sfb-admin__log { width: 100%; border-collapse: collapse; font-size: .88rem; }
  .sfb-admin__log th, .sfb-admin__log td { border-bottom: 1px solid #eee1d4; padding: 8px 6px; text-align: left; vertical-align: top; }
  .sfb-admin__log th { color: #806958; font-size: .7rem; letter-spacing: .04em; text-transform: uppercase; }
  .sfb-admin__log code { font-size: .78rem; }
  .sfb-admin__status-ok { color: #276545; font-weight: 700; }
  .sfb-admin__status-skip { color: #925817; font-weight: 700; }
  .sfb-admin__status-error { color: #8b342c; font-weight: 700; }
  .sfb-admin__pace { display: grid; gap: 12px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 14px 0; }
  .sfb-admin__pre { background: #fffaf4; border: 1px solid #eadbc9; border-radius: 9px; font-size: .8rem; overflow: auto; padding: 10px 12px; white-space: pre-wrap; }
  @media (max-width: 800px) { .sfb-admin__pace { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 700px) { .sfb-admin { padding: 16px 12px 36px; } .sfb-admin__header { padding: 20px; } .sfb-admin__filters, .sfb-admin__composer, .sfb-admin__reply-form { grid-template-columns: 1fr; } .sfb-admin__message--reply { margin-left: 12px; } }
</style>

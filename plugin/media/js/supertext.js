/**
 * Supertext Translation for Joomla — the "Supertext" toolbar dialog.
 * @license GNU General Public License version 2 or later
 */
((Joomla, document) => {
  'use strict';

  const options = Joomla.getOptions('plg_system_supertext') || {};
  const t = (key, ...args) => {
    let s = Joomla.Text._(`PLG_SYSTEM_SUPERTEXT_JS_${key}`);
    args.forEach((a) => { s = s.replace('%s', a); });
    return s;
  };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const post = async (data) => {
    const body = new FormData();
    body.append(options.token, '1');
    Object.entries(data).forEach(([k, v]) => {
      if (Array.isArray(v)) v.forEach((x) => body.append(`${k}[]`, x));
      else body.append(k, v);
    });
    const res = await fetch(options.endpoint, { method: 'POST', body, credentials: 'same-origin' });
    let json;
    try { json = await res.json(); } catch (e) { throw new Error(`HTTP ${res.status}`); }
    if (!res.ok || json.success === false) throw new Error(json.message || `HTTP ${res.status}`);
    return Array.isArray(json.data) ? json.data[0] : json.data;
  };

  const selectedIds = () => {
    if (options.mode === 'edit') return [options.articleId];
    return [...document.querySelectorAll('input[name="cid[]"]:checked')].map((el) => Number(el.value)).filter(Boolean);
  };

  let dialog;

  const open = async () => {
    const ids = selectedIds();
    if (!ids.length) return;

    dialog?.remove();
    dialog = document.createElement('dialog');
    dialog.className = 'supertext-dialog';
    dialog.innerHTML = `
      <form method="dialog">
        <header><h2>${esc(t('DIALOG_TITLE'))}</h2>
          <button type="button" class="btn-close" data-close aria-label="${esc(t('CLOSE'))}"></button></header>
        <div class="supertext-body"><p class="supertext-loading">${esc(t('LOADING'))}</p></div>
        <footer></footer>
      </form>`;
    document.body.append(dialog);
    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => dialog.remove());
    dialog.showModal();

    const body = dialog.querySelector('.supertext-body');
    const footer = dialog.querySelector('footer');

    if (!options.configured) {
      body.innerHTML = `<div class="alert alert-warning">${esc(t('NOT_CONFIGURED'))}</div>`;
      footer.innerHTML = `<button type="button" class="btn btn-secondary" data-close>${esc(t('CLOSE'))}</button>`;
      footer.querySelector('[data-close]').addEventListener('click', () => dialog.close());
      return;
    }

    let infos;
    try {
      infos = await Promise.all(ids.map((id) => post({ action: 'describe', id })));
    } catch (e) {
      body.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
      return;
    }

    // Merge target languages over all selected articles.
    const langs = new Map();
    infos.forEach((info) => info.languages.forEach((l) => {
      const entry = langs.get(l.tag) || { ...l, existingCount: 0 };
      if (l.existing) entry.existingCount += 1;
      langs.set(l.tag, entry);
    }));

    const intro = ids.length === 1
      ? t('INTRO_ONE', esc(infos[0].sourceTitle))
      : t('INTRO_MANY', String(ids.length));

    body.innerHTML = `
      <p>${intro}</p>
      ${options.mode === 'edit' ? `<p class="small text-muted">${esc(t('SAVE_FIRST'))}</p>` : ''}
      <fieldset><legend>${esc(t('TARGETS'))}</legend>
        ${[...langs.values()].map((l) => `
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="st-${esc(l.tag)}" value="${esc(l.tag)}" ${l.existingCount ? '' : 'checked'}>
            <label class="form-check-label" for="st-${esc(l.tag)}">${esc(l.title)} <code>${esc(l.tag)}</code>
              ${l.published ? '' : `<span class="badge bg-secondary">${esc(t('UNPUBLISHED'))}</span>`}
              ${l.existingCount ? `<span class="badge bg-warning text-dark">${esc(ids.length === 1 ? t('EXISTS') : t('EXISTS_SOME', String(l.existingCount)))}</span>` : ''}
            </label>
          </div>`).join('')}
      </fieldset>
      <div class="supertext-overwrite alert alert-warning" hidden>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="st-overwrite">
          <label class="form-check-label" for="st-overwrite"><strong>${esc(t('OVERWRITE'))}</strong></label>
        </div>
        <p class="small mb-0">${esc(t('OVERWRITE_HINT'))}</p>
      </div>
      <p class="small text-muted">${esc(t('REVIEW_HINT'))}</p>`;

    footer.innerHTML = `
      <button type="button" class="btn btn-secondary" data-close>${esc(t('CANCEL'))}</button>
      <button type="button" class="btn btn-primary" data-go>${esc(t('TRANSLATE'))}</button>`;
    footer.querySelector('[data-close]').addEventListener('click', () => dialog.close());

    const boxes = [...body.querySelectorAll('fieldset input')];
    const warn = body.querySelector('.supertext-overwrite');
    const go = footer.querySelector('[data-go]');
    const update = () => {
      const chosen = boxes.filter((b) => b.checked);
      warn.hidden = !chosen.some((b) => langs.get(b.value).existingCount);
      go.disabled = chosen.length === 0;
    };
    boxes.forEach((b) => b.addEventListener('change', update));
    update();

    go.addEventListener('click', async () => {
      const targets = boxes.filter((b) => b.checked).map((b) => b.value);
      const overwrite = body.querySelector('#st-overwrite').checked ? '1' : '0';
      go.disabled = true;
      go.innerHTML = `<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ${esc(t('TRANSLATING'))}`;
      boxes.forEach((b) => { b.disabled = true; });

      try {
        const { results } = await post({ action: 'translate', ids, targets, overwrite });

        // The open editor holds the article's associations in its form: add the new
        // translations there, or saving the article afterwards would unlink them.
        if (options.mode === 'edit') {
          results.filter((r) => r.id && (r.status === 'created' || r.status === 'updated')).forEach((r) => {
            const key = r.language.replace(/-/g, '_');
            const value = document.getElementById(`jform_associations_${key}_id`);
            const title = document.getElementById(`jform_associations_${key}`);
            if (!value) return;
            const changed = value.value !== String(r.id);
            value.value = String(r.id);
            if (title) title.value = r.title;
            if (changed) value.dispatchEvent(new CustomEvent('change', { bubbles: true, cancelable: true }));
          });
        }

        const names = new Map([...langs.values()].map((l) => [l.tag, l.title]));
        body.innerHTML = `<ul class="supertext-results list-unstyled">${results.map((r) => {
          const lang = esc(names.get(r.language) || r.language);
          const link = r.id ? ` <a href="${esc(options.editUrl + r.id)}">${esc(t('OPEN'))}</a>` : '';
          switch (r.status) {
            case 'created': return `<li class="text-success"><span class="icon-check" aria-hidden="true"></span> ${t('RESULT_CREATED', lang, esc(r.title))}${link}</li>`;
            case 'updated': return `<li class="text-success"><span class="icon-check" aria-hidden="true"></span> ${t('RESULT_UPDATED', lang, esc(r.title))}${link}</li>`;
            case 'skipped': return `<li class="text-muted"><span class="icon-minus" aria-hidden="true"></span> ${t('RESULT_SKIPPED', lang)}${link}</li>`;
            default: return `<li class="text-danger"><span class="icon-times" aria-hidden="true"></span> ${t('RESULT_ERROR', lang || '#' + r.article, esc(r.message))}</li>`;
          }
        }).join('')}</ul><p class="small text-muted">${esc(t('REVIEW_HINT'))}</p>`;
      } catch (e) {
        body.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger">${esc(e.message)}</div>`);
      }

      footer.innerHTML = `<button type="button" class="btn btn-primary" data-close>${esc(t('CLOSE'))}</button>`;
      footer.querySelector('[data-close]').addEventListener('click', () => {
        dialog.close();
        if (options.mode === 'list') window.location.reload();
      });
    });
  };

  document.addEventListener('click', (event) => {
    if (event.target.closest('.supertext-open')) {
      event.preventDefault();
      open();
    }
  });
})(Joomla, document);

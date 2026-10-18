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

    const names = new Map([...langs.values()].map((l) => [l.tag, l.title]));
    const closeButton = (reload) => {
      footer.innerHTML = `<button type="button" class="btn btn-primary" data-close>${esc(t('CLOSE'))}</button>`;
      footer.querySelector('[data-close]').addEventListener('click', () => {
        dialog.close();
        if (reload && options.mode === 'list') window.location.reload();
      });
    };
    // The open editor holds the article's associations in its form: add new translations
    // there, or saving the article afterwards would unlink them.
    const syncAssociations = (saved) => {
      if (options.mode !== 'edit') return;
      saved.filter((r) => r.id && r.language).forEach((r) => {
        const key = r.language.replace(/-/g, '_');
        const value = document.getElementById(`jform_associations_${key}_id`);
        const title = document.getElementById(`jform_associations_${key}`);
        if (!value) return;
        const changed = value.value !== String(r.id);
        value.value = String(r.id);
        if (title) title.value = r.title;
        if (changed) value.dispatchEvent(new CustomEvent('change', { bubbles: true, cancelable: true }));
      });
    };
    const cancelButton = () => `<button type="button" class="btn btn-secondary" data-close>${esc(t('CANCEL'))}</button>`;
    const wireCancel = () => footer.querySelector('[data-close]').addEventListener('click', () => dialog.close());

    // Tabs: translate with the API, export a file for translation, import a translated file.
    const nav = document.createElement('div');
    nav.className = 'supertext-tabs btn-group mb-3';
    nav.setAttribute('role', 'group');
    nav.innerHTML = [['translate', t('TRANSLATE')], ['export', t('EXPORT')], ['import', t('IMPORT')]]
      .map(([key, label]) => `<button type="button" class="btn btn-outline-secondary" data-tab="${key}">${esc(label)}</button>`).join('');
    const pane = document.createElement('div');
    body.replaceChildren(nav, pane);

    const showTranslate = () => {
      if (!options.configured) {
        pane.innerHTML = `<div class="alert alert-warning">${esc(t('NOT_CONFIGURED'))}</div>`;
        footer.innerHTML = cancelButton();
        wireCancel();
        return;
      }
      const intro = ids.length === 1
        ? t('INTRO_ONE', esc(infos[0].sourceTitle))
        : t('INTRO_MANY', String(ids.length));

      pane.innerHTML = `
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

      const boxes = [...pane.querySelectorAll('fieldset input')];
      const warn = pane.querySelector('.supertext-overwrite');
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
        const overwrite = pane.querySelector('#st-overwrite').checked ? '1' : '0';
        go.disabled = true;
        go.innerHTML = `<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ${esc(t('TRANSLATING'))}`;
        boxes.forEach((b) => { b.disabled = true; });

        try {
          const { results } = await post({ action: 'translate', ids, targets, overwrite });

          syncAssociations(results.filter((r) => r.status === 'created' || r.status === 'updated'));

          pane.innerHTML = `<ul class="supertext-results list-unstyled">${results.map((r) => {
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
          pane.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger">${esc(e.message)}</div>`);
        }

        footer.innerHTML = `<button type="button" class="btn btn-primary" data-close>${esc(t('CLOSE'))}</button>`;
        footer.querySelector('[data-close]').addEventListener('click', () => {
          dialog.close();
          if (options.mode === 'list') window.location.reload();
        });
      });
    };

    const showExport = () => {
      if (ids.length !== 1) {
        pane.innerHTML = `<div class="alert alert-info">${esc(t('EXPORT_ONE'))}</div>`;
        footer.innerHTML = cancelButton();
        wireCancel();
        return;
      }
      pane.innerHTML = `
        <p class="small text-muted">${esc(t('EXPORT_HINT'))}</p>
        <fieldset class="mb-3"><legend>${esc(t('FORMAT'))}</legend>
          <div class="form-check"><input class="form-check-input" type="radio" name="st-format" id="st-format-xliff" value="xliff" checked>
            <label class="form-check-label" for="st-format-xliff">${esc(t('FORMAT_XLIFF'))}</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="st-format" id="st-format-html" value="html">
            <label class="form-check-label" for="st-format-html">${esc(t('FORMAT_HTML'))}</label></div>
        </fieldset>
        <fieldset class="supertext-export-targets"><legend>${esc(t('TARGETS'))}</legend></fieldset>`;
      footer.innerHTML = `${cancelButton()}
        <button type="button" class="btn btn-primary" data-go><span class="icon-download" aria-hidden="true"></span> ${esc(t('DOWNLOAD'))}</button>`;
      wireCancel();
      const go = footer.querySelector('[data-go]');
      const list = pane.querySelector('.supertext-export-targets');
      const format = () => pane.querySelector('input[name="st-format"]:checked').value;
      const chosen = () => [...list.querySelectorAll('input:checked')].map((b) => b.value);
      const renderTargets = () => {
        const before = chosen();
        const type = format() === 'html' ? 'radio' : 'checkbox';
        list.querySelectorAll('.form-check').forEach((el) => el.remove());
        [...langs.values()].forEach((l, i) => {
          const checked = type === 'radio' ? (before[0] ?? [...langs.keys()][0]) === l.tag : before.length ? before.includes(l.tag) : i === 0;
          list.insertAdjacentHTML('beforeend', `
            <div class="form-check">
              <input class="form-check-input" type="${type}" name="st-export-target" id="st-x-${esc(l.tag)}" value="${esc(l.tag)}" ${checked ? 'checked' : ''}>
              <label class="form-check-label" for="st-x-${esc(l.tag)}">${esc(l.title)} <code>${esc(l.tag)}</code></label>
            </div>`);
        });
        list.querySelectorAll('input').forEach((b) => b.addEventListener('change', () => { go.disabled = chosen().length === 0; }));
        go.disabled = chosen().length === 0;
      };
      pane.querySelectorAll('input[name="st-format"]').forEach((r) => r.addEventListener('change', renderTargets));
      renderTargets();

      go.addEventListener('click', async () => {
        pane.querySelector('.alert-danger')?.remove();
        go.disabled = true;
        try {
          const data = new FormData();
          data.append(options.token, '1');
          data.append('action', 'export');
          data.append('id', String(ids[0]));
          data.append('format', format());
          chosen().forEach((tag) => data.append('targets[]', tag));
          const res = await fetch(options.endpoint, { method: 'POST', body: data, credentials: 'same-origin' });
          const type = res.headers.get('Content-Type') || '';
          if (!res.ok || type.includes('application/json')) {
            let message = `HTTP ${res.status}`;
            try { message = (await res.json()).message || message; } catch (e) { /* not JSON */ }
            throw new Error(message);
          }
          const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '')?.[1] || 'supertext.xlf';
          const url = URL.createObjectURL(await res.blob());
          const a = document.createElement('a');
          a.href = url;
          a.download = name;
          document.body.append(a);
          a.click();
          a.remove();
          setTimeout(() => URL.revokeObjectURL(url), 10000);
        } catch (e) {
          pane.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger">${esc(e.message)}</div>`);
        }
        go.disabled = false;
      });
    };

    const showImport = () => {
      pane.innerHTML = `
        <p class="small text-muted">${esc(t('IMPORT_HINT'))}</p>
        <div class="mb-3">
          <label class="form-label" for="st-file">${esc(t('FILE'))}</label>
          <input class="form-control" type="file" id="st-file" accept=".xlf,.xliff,.xml,.html,.htm">
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="st-include-changed">
          <label class="form-check-label" for="st-include-changed">${esc(t('INCLUDE_CHANGED'))}</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="st-import-overwrite">
          <label class="form-check-label" for="st-import-overwrite">${esc(t('OVERWRITE'))}</label>
        </div>
        <p class="small text-muted">${esc(t('OVERWRITE_HINT'))}</p>`;
      footer.innerHTML = `${cancelButton()}
        <button type="button" class="btn btn-primary" data-go disabled><span class="icon-upload" aria-hidden="true"></span> ${esc(t('IMPORT'))}</button>`;
      wireCancel();
      const go = footer.querySelector('[data-go]');
      const file = pane.querySelector('#st-file');
      file.addEventListener('change', () => { go.disabled = !file.files.length; });

      go.addEventListener('click', async () => {
        pane.querySelector('.alert-danger')?.remove();
        if (!file.files.length) {
          pane.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger">${esc(t('NO_FILE'))}</div>`);
          return;
        }
        go.disabled = true;
        go.innerHTML = `<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ${esc(t('IMPORTING'))}`;
        try {
          const { results } = await post({
            action: 'import',
            file: file.files[0],
            include_changed: pane.querySelector('#st-include-changed').checked ? '1' : '0',
            overwrite: pane.querySelector('#st-import-overwrite').checked ? '1' : '0',
          });
          syncAssociations(results.filter((r) => r.saved && r.saved.status !== 'skipped')
            .map((r) => ({ id: r.saved.id, title: r.saved.title, language: r.language })));
          pane.innerHTML = `<ul class="supertext-results list-unstyled">${results.map((r) => {
            const lang = esc(names.get(r.language) || r.language || r.reference);
            const saved = r.saved || {};
            const link = saved.id ? ` <a href="${esc(options.editUrl + saved.id)}">${esc(t('OPEN'))}</a>` : '';
            let head;
            if (r.message) head = `<span class="text-danger"><span class="icon-times" aria-hidden="true"></span> ${t('RESULT_ERROR', lang, esc(r.message))}</span>`;
            else if (saved.status === 'created') head = `<span class="text-success"><span class="icon-check" aria-hidden="true"></span> ${t('RESULT_CREATED', lang, esc(saved.title))}</span>${link}`;
            else if (saved.status === 'updated') head = `<span class="text-success"><span class="icon-check" aria-hidden="true"></span> ${t('RESULT_UPDATED', lang, esc(saved.title))}</span>${link}`;
            else if (saved.status === 'skipped') head = `<span class="text-muted"><span class="icon-minus" aria-hidden="true"></span> ${t('RESULT_SKIPPED', lang)}</span>${link}`;
            else head = `<strong>${lang}</strong>`;
            const c = r.counts || {};
            const rows = [['SUMMARY_OK', c.imported], ['SUMMARY_CHANGED', c.changed], ['SUMMARY_UNTRANSLATED', c.untranslated], ['SUMMARY_INVALID', c.invalid], ['SUMMARY_MISSING', c.missing]]
              .filter(([key, n]) => key === 'SUMMARY_OK' || n > 0)
              .map(([key, n]) => `<li>${esc(t(key, String(n || 0)))}</li>`).join('');
            return `<li>${head}${r.message ? '' : `<ul class="small">${rows}</ul>`}</li>`;
          }).join('')}</ul><p class="small text-muted">${esc(t('REVIEW_HINT'))}</p>`;
        } catch (e) {
          pane.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger">${esc(e.message)}</div>`);
          go.disabled = false;
          go.innerHTML = `<span class="icon-upload" aria-hidden="true"></span> ${esc(t('IMPORT'))}`;
          return;
        }
        closeButton(true);
      });
    };

    const tabs = { translate: showTranslate, export: showExport, import: showImport };
    const select = (key) => {
      nav.querySelectorAll('[data-tab]').forEach((b) => {
        b.classList.toggle('active', b.dataset.tab === key);
        b.setAttribute('aria-pressed', b.dataset.tab === key ? 'true' : 'false');
      });
      tabs[key]();
    };
    nav.addEventListener('click', (event) => {
      const tab = event.target.closest('[data-tab]');
      if (tab) select(tab.dataset.tab);
    });
    select(options.configured ? 'translate' : 'export');
  };

  document.addEventListener('click', (event) => {
    if (event.target.closest('.supertext-open')) {
      event.preventDefault();
      open();
    }
  });
})(Joomla, document);

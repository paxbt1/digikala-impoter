jQuery(function ($) {
  const ajaxUrl = (window.DKI_Admin && DKI_Admin.ajax_url) ? DKI_Admin.ajax_url : ajaxurl;
  const nonce = (window.DKI_Admin && DKI_Admin.nonce) ? DKI_Admin.nonce : '';

  // SEARCH TAB
  const $searchBtn = $('#dki-search-btn');
  const $searchQuery = $('#dki-search-query');
  const $results = $('#dki-search-results');
  const $status = $('#dki-search-status');
  const $log = $('#dki-import-log');
  const $prev = $('#dki-prev-page');
  const $next = $('#dki-next-page');
  const $pageInd = $('#dki-page-indicator');
  const $clearLog = $('#dki-clear-log');

  let currentPage = 1;

  function escapeHtml(str) {
    return $('<div/>').text(str || '').html();
  }

  function log(msg, type = 'info') {
    if (!$log.length) return;
    const cls = (type === 'error') ? 'dki-log-error' : 'dki-log-info';
    const time = new Date().toLocaleTimeString();
    $log.prepend('<div class="' + cls + '"><span class="dki-log-time">' + time + '</span> ' + msg + '</div>');
  }

  function setStatus(text, type = '') {
    if (!$status.length) return;
    $status.removeClass('is-error is-ok');
    if (type === 'error') $status.addClass('is-error');
    if (type === 'ok') $status.addClass('is-ok');
    $status.text(text || '');
  }

  function renderResults(items) {
    if (!items || !items.length) {
      $results.html('');
      return;
    }

    let html = '<table class="widefat fixed striped dki-table"><thead><tr>' +
      '<th style="width:70px;">عکس</th>' +
      '<th>عنوان</th>' +
      '<th style="width:160px;">قیمت</th>' +
      '<th style="width:220px;">لینک</th>' +
      '<th style="width:120px;">عملیات</th>' +
      '</tr></thead><tbody>';

    items.forEach(function (item) {
      const img = item.image ? '<img class="dki-thumb" src="' + escapeHtml(item.image) + '" alt="">' : '<span class="dki-noimg">—</span>';
      const title = escapeHtml(item.title_fa || item.title_en || '');
      const link = item.url ? '<a href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener">باز کردن</a>' : '—';

      let priceTxt = '—';
      if (item.price_store && Number(item.price_store) > 0) {
        priceTxt = escapeHtml(String(item.price_store).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
      } else if (item.price_rial && Number(item.price_rial) > 0) {
        priceTxt = escapeHtml(String(item.price_rial).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
      }

      html += '<tr>' +
        '<td>' + img + '</td>' +
        '<td><div class="dki-title-cell">' + title +
          (item.id ? '<div class="dki-sub">ID: ' + escapeHtml(item.id) + '</div>' : '') +
        '</div></td>' +
        '<td><span class="dki-price">' + priceTxt + '</span></td>' +
        '<td class="dki-link-cell">' + link + '</td>' +
        '<td><button class="button button-primary dki-import-btn" data-url="' + escapeHtml(item.url) + '">درج</button></td>' +
        '</tr>';
    });

    html += '</tbody></table>';
    $results.html(html);
  }

  function doSearch(page) {
    const q = ($searchQuery.val() || '').trim();
    if (!q) {
      setStatus('لطفاً عبارت جستجو را وارد کنید.', 'error');
      return;
    }

    currentPage = page || 1;
    $pageInd.text(String(currentPage));

    setStatus('در حال جستجو در دیجی‌کالا...');
    $results.html('');

    $.post(ajaxUrl, {
      action: 'dki_search_products',
      nonce: nonce,
      q: q,
      page: currentPage
    }).done(function (resp) {
      if (!resp || !resp.success) {
        setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'خطا در جستجو', 'error');
        return;
      }
      const items = (resp.data && resp.data.results) ? resp.data.results : [];
      if (!items.length) {
        setStatus('محصولی یافت نشد.', 'error');
        return;
      }
      setStatus('تعداد ' + items.length + ' نتیجه یافت شد.', 'ok');
      renderResults(items);
    }).fail(function () {
      setStatus('خطای ارتباط با سرور.', 'error');
    });
  }

  $searchBtn.on('click', function (e) {
    e.preventDefault();
    doSearch(1);
  });

  $prev.on('click', function (e) {
    e.preventDefault();
    if (currentPage > 1) doSearch(currentPage - 1);
  });

  $next.on('click', function (e) {
    e.preventDefault();
    doSearch(currentPage + 1);
  });

  $results.on('click', '.dki-import-btn', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const url = $btn.data('url');
    if (!url) return;

    $btn.prop('disabled', true).text('در حال درج...');

    $.post(ajaxUrl, {
      action: 'dki_import_product',
      nonce: nonce,
      url: url
    }).done(function (resp) {
      if (!resp || !resp.success) {
        const msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'خطا در درج محصول';
        log('❌ ' + escapeHtml(msg), 'error');
        return;
      }
      const d = resp.data || {};
      const link = d.edit_link ? ' — <a href="' + escapeHtml(d.edit_link) + '">ویرایش محصول</a>' : '';
      log('✅ محصول ایجاد/به‌روزرسانی شد. (ID: ' + escapeHtml(d.product_id) + ')' + link, 'info');
    }).fail(function () {
      log('❌ خطای ارتباط با سرور هنگام درج محصول.', 'error');
    }).always(function () {
      $btn.prop('disabled', false).text('درج');
    });
  });

  $clearLog.on('click', function (e) {
    e.preventDefault();
    $log.html('');
  });

  // BY ID TAB
  const $byIdBtn = $('#dki-import-by-id-btn');
  const $byIdInput = $('#dki-product-id');
  const $byIdStatus = $('#dki-by-id-status');
  const $byIdLog = $('#dki-by-id-log');

  function byIdStatus(text, type = '') {
    if (!$byIdStatus.length) return;
    $byIdStatus.removeClass('is-error is-ok');
    if (type === 'error') $byIdStatus.addClass('is-error');
    if (type === 'ok') $byIdStatus.addClass('is-ok');
    $byIdStatus.text(text || '');
  }
  function byIdLog(msg, type='info') {
    if (!$byIdLog.length) return;
    const cls = (type === 'error') ? 'dki-log-error' : 'dki-log-info';
    const time = new Date().toLocaleTimeString();
    $byIdLog.prepend('<div class="' + cls + '"><span class="dki-log-time">' + time + '</span> ' + msg + '</div>');
  }

  $byIdBtn.on('click', function (e) {
    e.preventDefault();
    const id = parseInt($byIdInput.val(), 10);
    if (!id || id <= 0) {
      byIdStatus('شناسه محصول نامعتبر است.', 'error');
      return;
    }
    byIdStatus('در حال دریافت اطلاعات محصول...', '');
    $byIdBtn.prop('disabled', true).text('در حال درج...');

    $.post(ajaxUrl, {
      action: 'dki_import_product_by_id',
      nonce: nonce,
      product_id: id
    }).done(function (resp) {
      if (!resp || !resp.success) {
        const msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'خطا در درج محصول';
        byIdStatus(msg, 'error');
        byIdLog('❌ ' + escapeHtml(msg), 'error');
        return;
      }
      const d = resp.data || {};
      const link = d.edit_link ? ' — <a href="' + escapeHtml(d.edit_link) + '">ویرایش محصول</a>' : '';
      byIdStatus('با موفقیت انجام شد.', 'ok');
      byIdLog('✅ محصول ایجاد/به‌روزرسانی شد. (ID: ' + escapeHtml(d.product_id) + ')' + link, 'info');
    }).fail(function () {
      byIdStatus('خطای ارتباط با سرور.', 'error');
      byIdLog('❌ خطای ارتباط با سرور.', 'error');
    }).always(function () {
      $byIdBtn.prop('disabled', false).text('درج');
    });
  });

  // SETTINGS TAB
  const $saveSettings = $('#dki-save-settings');
  const $settingsStatus = $('#dki-settings-status');

  $saveSettings.on('click', function (e) {
    e.preventDefault();
    $settingsStatus.removeClass('is-error is-ok').text('در حال ذخیره...');

    $.post(ajaxUrl, {
      action: 'dki_save_settings',
      nonce: nonce,
      price_unit: $('#dki-price-unit').val(),
      post_status: $('#dki-post-status').val(),
      update_existing: $('#dki-update-existing').val(),
      create_global_attributes: $('#dki-create-global-attrs').val(),
      image_limit: $('#dki-image-limit').val(),
      timeout: $('#dki-timeout').val()
    }).done(function (resp) {
      if (!resp || !resp.success) {
        const msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'خطا در ذخیره تنظیمات';
        $settingsStatus.addClass('is-error').text(msg);
        return;
      }
      $settingsStatus.addClass('is-ok').text('ذخیره شد ✅');
    }).fail(function () {
      $settingsStatus.addClass('is-error').text('خطای ارتباط با سرور');
    });
  });

});

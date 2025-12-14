jQuery(function ($) {
  const ajaxUrl = DKI_Admin.ajax_url;
  const nonce = DKI_Admin.nonce;

  const $log = $('#dki-import-log');

  function now() {
    const d = new Date();
    return d.toLocaleTimeString('fa-IR');
  }

  function log(msg, type = 'info') {
    const cls = type === 'error' ? 'dki-log-error' : 'dki-log-info';
    $log.append('<div class="' + cls + '">' + now() + ' ' + msg + '</div>');
    $log.scrollTop($log[0].scrollHeight);
  }

  function selectedCats($panel) {
    const cats = [];
    // Find checked product_cat checkboxes inside current panel (its sidebar)
    $panel.find('.dki-wc-cat-tree input[type="checkbox"][name="tax_input[product_cat][]"]:checked').each(function () {
      cats.push($(this).val());
    });
    return cats;
  }

  // Tabs
  $('.dki-tab').on('click', function () {
    const tab = $(this).data('tab');
    $('.dki-tab').removeClass('active');
    $(this).addClass('active');
    $('.dki-tab-panel').removeClass('active');
    $('#' + tab).addClass('active');
  });

  // Search
  $('#dki-search-btn').on('click', function (e) {
    e.preventDefault();
    const $panel = $('#tab-search');
    const q = $('#dki-search-query').val().trim();
    const $status = $('#dki-search-status');
    const $results = $('#dki-search-results');

    if (!q) {
      $status.text('لطفاً عبارت جستجو را وارد کنید.');
      return;
    }

    $status.text('در حال جستجو در دیجی‌کالا...');
    $results.empty();

    $.post(ajaxUrl, {
      action: 'dki_search_products',
      nonce,
      query: q,
      page: 1
    }).done(function (resp) {
      if (!resp || !resp.success) {
        $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'خطا در جستجو');
        return;
      }

      const items = resp.data.results || [];
      if (!items.length) {
        $status.text('محصولی یافت نشد.');
        return;
      }

      $status.text('تعداد ' + items.length + ' نتیجه یافت شد.');
      let html = '<table class="widefat fixed striped dki-table"><thead><tr>' +
        '<th style="width:64px;">تصویر</th><th>عنوان</th><th style="width:120px;">قیمت</th><th style="width:80px;">وضعیت</th><th style="width:140px;">عملیات</th></tr></thead><tbody>';

      items.forEach(function (item) {
        const img = item.image ? '<img class="dki-thumb" src="' + item.image + '" />' : '';
        const price = item.price ? item.price.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",") : '-';
        const st = item.stock_status === 'instock' ? 'موجود' : 'ناموجود';

        html += '<tr>' +
          '<td>' + img + '</td>' +
          '<td><div class="dki-title">' + $('<div/>').text(item.title_fa || item.title_en || '').html() + '</div>' +
          (item.url ? '<div class="dki-small"><a href="' + item.url + '" target="_blank" rel="noopener">مشاهده</a></div>' : '') +
          '</td>' +
          '<td>' + price + '</td>' +
          '<td>' + st + '</td>' +
          '<td><button class="button dki-import-btn" data-url="' + item.url + '">درج</button></td>' +
          '</tr>';
      });

      html += '</tbody></table>';
      $results.html(html);
    }).fail(function () {
      $status.text('خطای ارتباط با سرور.');
    });
  });

  $('#dki-search-results').on('click', '.dki-import-btn', function (e) {
    e.preventDefault();
    const $panel = $('#tab-search');
    const $btn = $(this);
    const url = $btn.data('url');
    if (!url) return;

    $btn.prop('disabled', true).text('در حال درج...');
    $.post(ajaxUrl, {
      action: 'dki_import_product',
      nonce,
      url,
      cats: selectedCats($panel)
    }).done(function (resp) {
      if (!resp || !resp.success) {
        const msg = resp && resp.data && resp.data.message ? resp.data.message : 'خطا در درج محصول';
        log('❌ ' + msg, 'error');
        return;
      }
      const d = resp.data;
      const link = d.edit_link ? ' — <a href="' + d.edit_link + '">ویرایش محصول</a>' : '';
      log('✅ ' + (d.message || 'محصول ایجاد/به‌روزرسانی شد.') + ' (ID: ' + d.product_id + ')' + link, 'info');
    }).fail(function () {
      log('❌ خطای ارتباط با سرور هنگام درج محصول.', 'error');
    }).always(function () {
      $btn.prop('disabled', false).text('درج');
    });
  });

  // Import by ID
  $('#dki-import-by-id-btn').on('click', function (e) {
    e.preventDefault();
    const $panel = $('#tab-id');
    const id = parseInt($('#dki-product-id').val(), 10);
    const $status = $('#dki-id-status');

    if (!id) {
      $status.text('شناسه معتبر وارد کنید.');
      return;
    }
    $status.text('در حال دریافت و درج...');
    $.post(ajaxUrl, {
      action: 'dki_import_by_id',
      nonce,
      product_id: id,
      cats: selectedCats($panel)
    }).done(function (resp) {
      if (!resp || !resp.success) {
        $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');
        log('❌ ' + ($status.text()), 'error');
        return;
      }
      const d = resp.data;
      const link = d.edit_link ? ' — <a href="' + d.edit_link + '">ویرایش محصول</a>' : '';
      $status.text('انجام شد.');
      log('✅ ' + (d.message || 'محصول ایجاد/به‌روزرسانی شد.') + ' (ID: ' + d.product_id + ')' + link, 'info');
    }).fail(function () {
      $status.text('خطای ارتباط با سرور.');
      log('❌ خطای ارتباط با سرور هنگام درج محصول.', 'error');
    });
  });

  // Category fetch
  let categoryCache = { items: [], url: '', total_pages: 1 };

  $('#dki-category-fetch-btn').on('click', function (e) {
    e.preventDefault();
    const $panel = $('#tab-category');
    const url = $('#dki-category-url').val().trim();
    const $status = $('#dki-category-status');
    const $results = $('#dki-category-results');

    if (!url) { $status.text('لینک را وارد کنید.'); return; }

    $status.text('در حال خواندن صفحه 1 ...');
    $results.empty();
    categoryCache = { items: [], url, total_pages: 1 };
    $('#dki-category-import-all-btn').prop('disabled', true);

    function fetchPage(page) {
      return $.post(ajaxUrl, { action: 'dki_category_fetch_page', nonce, url, page });
    }

    fetchPage(1).done(function (resp) {
      if (!resp || !resp.success) {
        $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');
        return;
      }
      categoryCache.total_pages = resp.data.total_pages || 1;
      categoryCache.items = resp.data.items || [];
      renderCategoryTable(categoryCache.items);
      $status.text('صفحه 1 خوانده شد. مجموع صفحات: ' + categoryCache.total_pages);
      $('#dki-category-import-all-btn').prop('disabled', categoryCache.items.length === 0);

      // optionally: load all pages if small? keep manual for safety
    }).fail(function () {
      $status.text('خطای ارتباط با سرور.');
    });

    function renderCategoryTable(items) {
      if (!items.length) { $results.html('<div class="dki-empty">موردی یافت نشد.</div>'); return; }

      let html = '<table class="widefat fixed striped dki-table"><thead><tr>' +
        '<th style="width:64px;">تصویر</th><th>عنوان</th><th style="width:120px;">قیمت</th><th style="width:80px;">وضعیت</th><th style="width:80px;">انتخاب</th></tr></thead><tbody>';

      items.forEach(function (item) {
        const img = item.image ? '<img class="dki-thumb" src="' + item.image + '" />' : '';
        const price = item.price ? item.price.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",") : '-';
        const st = item.stock_status === 'instock' ? 'موجود' : 'ناموجود';
        html += '<tr>' +
          '<td>' + img + '</td>' +
          '<td><div class="dki-title">' + $('<div/>').text(item.title_fa || '').html() + '</div>' +
          (item.url ? '<div class="dki-small"><a href="' + item.url + '" target="_blank" rel="noopener">مشاهده</a></div>' : '') +
          '</td>' +
          '<td>' + price + '</td>' +
          '<td>' + st + '</td>' +
          '<td><input type="checkbox" class="dki-cat-item" value="' + item.id + '" checked></td>' +
          '</tr>';
      });

      html += '</tbody></table>';
      $results.html(html);
    }
  });

  // Category import all (sequential, resilient)
  $('#dki-category-import-all-btn').on('click', function (e) {
    e.preventDefault();
    const $panel = $('#tab-category');
    const $status = $('#dki-category-status');
    const ids = [];
    $('#dki-category-results .dki-cat-item:checked').each(function () {
      ids.push(parseInt($(this).val(), 10));
    });
    if (!ids.length) { $status.text('هیچ محصولی انتخاب نشده است.'); return; }

    $(this).prop('disabled', true);
    let idx = 0;

    function step() {
      if (idx >= ids.length) {
        $status.text('درج همه محصولات تمام شد.');
        $('#dki-category-import-all-btn').prop('disabled', false);
        return;
      }
      const id = ids[idx++];
      $status.text('در حال درج ' + idx + ' از ' + ids.length + ' ... (ID: ' + id + ')');

      $.post(ajaxUrl, {
        action: 'dki_import_by_id',
        nonce,
        product_id: id,
        cats: selectedCats($panel)
      }).done(function (resp) {
        if (!resp || !resp.success) {
          const msg = resp && resp.data && resp.data.message ? resp.data.message : 'خطا';
          log('❌ ' + msg + ' — (DK ID: ' + id + ')', 'error');
        } else {
          const d = resp.data;
          const link = d.edit_link ? ' — <a href="' + d.edit_link + '">ویرایش محصول</a>' : '';
          log('✅ ' + (d.message || 'محصول ایجاد/به‌روزرسانی شد.') + ' (ID: ' + d.product_id + ')' + link, 'info');
        }
      }).fail(function () {
        log('❌ خطای ارتباط با سرور هنگام درج محصول. — (DK ID: ' + id + ')', 'error');
      }).always(function () {
        // small delay to avoid rate limit
        setTimeout(step, 350);
      });
    }

    step();
  });

  // Settings save
  $('#dki-save-settings').on('click', function (e) {
    e.preventDefault();
    const $status = $('#dki-settings-status');
    $status.text('در حال ذخیره...');
    $.post(ajaxUrl, {
      action: 'dki_save_settings',
      nonce,
      price_mode: $('#dki-price-mode').val(),
      nofollow: $('#dki-credit-nofollow').val()
    }).done(function (resp) {
      if (!resp || !resp.success) {
        $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');
        return;
      }
      $status.text(resp.data.message || 'ذخیره شد.');
      log('✅ تنظیمات ذخیره شد.', 'info');
    }).fail(function () {
      $status.text('خطای ارتباط با سرور.');
    });
  });
});

jQuery(function ($) {
  const ajaxUrl = DKI_Admin.ajax_url;
  const nonce = DKI_Admin.nonce;
  const $log = $('#dki-import-log');

  function now() { return new Date().toLocaleTimeString('fa-IR'); }
  function esc(text) { return $('<div/>').text(text || '').html(); }

  function formatPrice(num) {
    if (num === null || typeof num === 'undefined') return 'ناموجود';
    const n = Number(num);
    if (!isFinite(n) || n <= 0) return 'ناموجود';
    return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function stockChip(status) {
    return status === 'instock'
      ? '<span class="dkiux-chip dkiux-chip-ok">موجود</span>'
      : '<span class="dkiux-chip dkiux-chip-no">ناموجود</span>';
  }

  function log(msg, type = 'info') {
    if (!$log.length) return;
    const cls = type === 'error' ? 'dki-log-error' : 'dki-log-info';
    $log.append('<div class="' + cls + '">' + now() + ' ' + msg + '</div>');
    $log.scrollTop($log[0].scrollHeight);
  }

  function selectedCats($panel) {
    const cats = [];
    $panel.find('input[type="checkbox"][name="tax_input[product_cat][]"]:checked').each(function () {
      cats.push($(this).val());
    });
    return cats;
  }

  function renderListTable(items, options = {}) {
    if (!items || !items.length) return '<div class="dkiux-empty">موردی یافت نشد.</div>';

    const showAction = !!options.showAction;
    const showCheckbox = !!options.showCheckbox;

    let html = '<div class="dkiux-table-wrap"><table class="dkiux-table"><thead><tr>';
    html += '<th>تصویر</th><th>عنوان</th><th>قیمت</th><th>وضعیت</th>';
    if (showCheckbox) html += '<th>انتخاب</th>';
    if (showAction) html += '<th>عملیات</th>';
    html += '</tr></thead><tbody>';

    items.forEach(function (item) {
      html += '<tr>';
      html += '<td>' + (item.image ? '<img class="dki-thumb" src="' + esc(item.image) + '" alt="">' : '') + '</td>';
      html += '<td><div class="dkiux-strong">' + esc(item.title_fa || item.title_en || '') + '</div>';
      if (item.url) html += '<div class="dkiux-mini"><a href="' + esc(item.url) + '" target="_blank" rel="noopener">مشاهده</a></div>';
      html += '</td>';
      html += '<td>' + formatPrice(item.price) + '</td>';
      html += '<td>' + stockChip(item.stock_status) + '</td>';
      if (showCheckbox) html += '<td><input type="checkbox" class="dkiux-check dki-cat-item" value="' + parseInt(item.id || 0, 10) + '" checked></td>';
      if (showAction) html += '<td><button class="dkiux-btn dkiux-btn-ghost dki-import-btn" data-url="' + esc(item.url || '') + '">درج</button></td>';
      html += '</tr>';
    });

    html += '</tbody></table></div>';
    return html;
  }

  if (DKI_Admin.is_importer_page) {
    $('#dki-tabs .dkiux-tab').on('click', function () {
      const tab = $(this).data('tab');
      $('#dki-tabs .dkiux-tab').removeClass('active');
      $(this).addClass('active');
      $('.dki-tab-panel').removeClass('active');
      $('#' + tab).addClass('active');
    });

    $('#dki-search-btn').on('click', function (e) {
      e.preventDefault();
      const q = $('#dki-search-query').val().trim();
      const $status = $('#dki-search-status');
      const $results = $('#dki-search-results');
      if (!q) return $status.text('عبارت جستجو را وارد کنید.');

      $status.text('در حال جستجو...');
      $results.empty();

      $.post(ajaxUrl, { action: 'dki_search_products', nonce, query: q, page: 1 })
        .done(function (resp) {
          if (!resp || !resp.success) return $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'خطا در جستجو');
          const items = resp.data.results || [];
          $status.text(items.length ? ('تعداد ' + items.length + ' نتیجه یافت شد.') : 'نتیجه‌ای پیدا نشد.');
          $results.html(renderListTable(items, { showAction: true }));
        })
        .fail(function () { $status.text('خطای ارتباط با سرور.'); });
    });

    $('#dki-search-results').on('click', '.dki-import-btn', function (e) {
      e.preventDefault();
      const $panel = $('#tab-search');
      const $btn = $(this);
      const url = $btn.data('url');
      if (!url) return;

      $btn.prop('disabled', true).text('در حال درج...');
      $.post(ajaxUrl, { action: 'dki_import_product', nonce, url, cats: selectedCats($panel) })
        .done(function (resp) {
          if (!resp || !resp.success) return log('❌ ' + (resp && resp.data && resp.data.message ? resp.data.message : 'خطا در درج'), 'error');
          const d = resp.data;
          const link = d.edit_link ? ' — <a href="' + esc(d.edit_link) + '">ویرایش محصول</a>' : '';
          log('✅ ' + (d.message || 'محصول ایجاد/به‌روزرسانی شد.') + ' (ID: ' + d.product_id + ')' + link);
        })
        .fail(function () { log('❌ خطای ارتباط با سرور هنگام درج.', 'error'); })
        .always(function () { $btn.prop('disabled', false).text('درج'); });
    });

    $('#dki-import-by-id-btn').on('click', function (e) {
      e.preventDefault();
      const $panel = $('#tab-id');
      const id = parseInt($('#dki-product-id').val(), 10);
      const $status = $('#dki-id-status');
      if (!id) return $status.text('شناسه معتبر وارد کنید.');

      $status.text('در حال درج...');
      $.post(ajaxUrl, { action: 'dki_import_by_id', nonce, product_id: id, cats: selectedCats($panel) })
        .done(function (resp) {
          if (!resp || !resp.success) {
            const msg = resp && resp.data && resp.data.message ? resp.data.message : 'خطا';
            $status.text(msg);
            return log('❌ ' + msg, 'error');
          }
          const d = resp.data;
          $status.text('انجام شد.');
          log('✅ ' + (d.message || 'محصول ایجاد/به‌روزرسانی شد.') + ' (ID: ' + d.product_id + ')');
        })
        .fail(function () { $status.text('خطای ارتباط با سرور.'); });
    });

    $('#dki-category-fetch-btn').on('click', function (e) {
      e.preventDefault();
      const url = $('#dki-category-url').val().trim();
      const $status = $('#dki-category-status');
      const $results = $('#dki-category-results');
      if (!url) return $status.text('لینک را وارد کنید.');

      $status.text('در حال خواندن...');
      $results.empty();
      $('#dki-category-import-all-btn').prop('disabled', true);

      $.post(ajaxUrl, { action: 'dki_category_fetch_page', nonce, url, page: 1 })
        .done(function (resp) {
          if (!resp || !resp.success) return $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');
          const items = resp.data.items || [];
          $status.text('صفحه 1 خوانده شد. مجموع صفحات: ' + (resp.data.total_pages || 1));
          $results.html(renderListTable(items, { showCheckbox: true }));
          $('#dki-category-import-all-btn').prop('disabled', !items.length);
        })
        .fail(function () { $status.text('خطای ارتباط با سرور.'); });
    });

    $('#dki-category-import-all-btn').on('click', function (e) {
      e.preventDefault();
      const $panel = $('#tab-category');
      const $status = $('#dki-category-status');
      const ids = [];
      $('#dki-category-results .dki-cat-item:checked').each(function () { ids.push(parseInt($(this).val(), 10)); });
      if (!ids.length) return $status.text('هیچ محصولی انتخاب نشده است.');

      const $btn = $(this);
      let idx = 0;
      $btn.prop('disabled', true);

      function step() {
        if (idx >= ids.length) {
          $status.text('درج همه محصولات تمام شد.');
          return $btn.prop('disabled', false);
        }
        const id = ids[idx++];
        $status.text('در حال درج ' + idx + ' از ' + ids.length + ' (ID: ' + id + ')');

        $.post(ajaxUrl, { action: 'dki_import_by_id', nonce, product_id: id, cats: selectedCats($panel) })
          .done(function (resp) {
            if (!resp || !resp.success) return log('❌ ' + (resp && resp.data && resp.data.message ? resp.data.message : 'خطا') + ' — (DK ID: ' + id + ')', 'error');
            log('✅ محصول ' + id + ' درج/به‌روزرسانی شد.');
          })
          .fail(function () { log('❌ خطای ارتباط با سرور — (DK ID: ' + id + ')', 'error'); })
          .always(function () { setTimeout(step, 350); });
      }

      step();
    });

    $('#dki-save-settings').on('click', function (e) {
      e.preventDefault();
      const $status = $('#dki-settings-status');
      $status.text('در حال ذخیره...');

      $.post(ajaxUrl, {
        action: 'dki_save_settings',
        nonce,
        price_mode: $('#dki-price-mode').val(),
        nofollow: $('#dki-credit-nofollow').val(),
        credit_enabled: $('#dki-credit-enabled').val(),
        credit_text_mode: $('#dki-credit-text-mode').val(),
        credit_text_custom: $('#dki-credit-text-custom').val(),
        alt_mode: $('#dki-image-alt-mode').val(),
        alt_fixed: $('#dki-image-alt-fixed').val(),
        price_adjust_mode: $('#dki-price-adjust-mode').val(),
        price_adjust_percent: $('#dki-price-adjust-percent').val(),
        price_round_mode: $('#dki-price-round-mode').val(),
        price_round_zeros: $('#dki-price-round-zeros').val(),
        server_cron_enabled: $('#dki-server-cron-enabled').val(),
        price_update_enabled: $('#dki-price-update-enabled').val(),
        price_update_period: $('#dki-price-update-period').val(),
        price_update_weekday: $('#dki-price-update-weekday').val(),
        price_update_time: $('#dki-price-update-time').val(),
        price_update_batch_size: $('#dki-price-update-batch-size').val()
      }).done(function (resp) {
        if (!resp || !resp.success) return $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');
        $status.text(resp.data.message || 'ذخیره شد.');
        log('✅ تنظیمات ذخیره شد.');
      }).fail(function () {
        $status.text('خطای ارتباط با سرور.');
      });
    });

    $('#dki-price-update-now').on('click', function (e) {
      e.preventDefault();
      const $status = $('#dki-settings-status');
      $status.text('در حال اجرای بروزرسانی دستی...');
      $.post(ajaxUrl, { action: 'dki_price_update_now', nonce })
        .done(function (resp) {
          if (!resp || !resp.success) return $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');
          const r = (resp.data && resp.data.result) ? resp.data.result : {};
          $status.text('انجام شد. موفق: ' + (r.ok || 0) + ' | ناموفق: ' + (r.failed || 0) + ' | باقی‌مانده: ' + (r.remaining || 0));
        })
        .fail(function () { $status.text('خطای ارتباط با سرور.'); });
    });

    $('#dki-clear-price-logs').on('click', function (e) {
      e.preventDefault();
      const $btn = $(this);
      if (!window.confirm('لاگ‌های بروزرسانی قیمت پاک شوند؟')) return;

      $btn.prop('disabled', true);
      $.post(ajaxUrl, { action: 'dki_clear_price_logs', nonce })
        .done(function (resp) {
          if (!resp || !resp.success) {
            alert(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');
            return;
          }
          $('#dki-price-logs-body').html('<tr><td colspan=\"4\">لاگی ثبت نشده است.</td></tr>');
        })
        .fail(function () { alert('خطای ارتباط با سرور.'); })
        .always(function () { $btn.prop('disabled', false); });
    });
  }

  if (DKI_Admin.is_product_edit) {
    const $modal = $('#dki-variation-modal');
    let activeVariationId = 0;
    let selectedProduct = null;
    let selectedVariant = null;

    function setStep(step) {
      $modal.find('.dkiux-step').removeClass('is-active');
      $modal.find('.dkiux-step[data-step="' + step + '"]').addClass('is-active');

      if (step === 1) {
        $('#dki-variation-step1').addClass('is-active');
        $('#dki-variation-step2').removeClass('is-active');
        $('#dki-variation-back-btn').hide();
        $('#dki-variation-next-btn').show();
        $('#dki-variation-apply-btn').hide();
      } else {
        $('#dki-variation-step1').removeClass('is-active');
        $('#dki-variation-step2').addClass('is-active');
        $('#dki-variation-back-btn').show();
        $('#dki-variation-next-btn').hide();
        $('#dki-variation-apply-btn').show();
      }
    }

    function resetModal() {
      selectedProduct = null;
      selectedVariant = null;
      $('#dki-variation-search-query').val('');
      $('#dki-variation-search-status').text('');
      $('#dki-variation-variant-status').text('');
      $('#dki-variation-search-results').empty();
      $('#dki-variation-variants-list').empty();
      $('#dki-variation-next-btn').prop('disabled', true);
      $('#dki-variation-apply-btn').prop('disabled', true);
      setStep(1);
    }

    function openModal(variationId) { activeVariationId = variationId; resetModal(); $modal.show(); }
    function closeModal() { activeVariationId = 0; $modal.hide(); resetModal(); }

    function findVariationWrap(variationId) {
      return $('.dki-open-variation-modal[data-variation-id="' + variationId + '"]').first().closest('.dki-variation-connect-wrap');
    }

    function setVariationLinkUI(variationId, data) {
      const $wrap = findVariationWrap(variationId);
      if (!$wrap.length) return;

      const dkp = parseInt((data && data.dkp) || 0, 10);
      const variantId = parseInt((data && data.variant_id) || 0, 10);
      const title = (data && data.title) ? data.title : '';
      const variantTitle = (data && data.variant_title) ? data.variant_title : '';

      $wrap.find('.dki-variation-dkp-input').val(dkp > 0 ? String(dkp) : '');
      $wrap.find('.dki-variation-dk-variant-id-input').val(variantId > 0 ? String(variantId) : '');
      $wrap.find('.dki-variation-dkp-badge').text('DKP: ' + (dkp > 0 ? dkp : 'متصل نیست'));
      $wrap.find('.dki-variation-dk-variant-badge').text('Variant: ' + (variantId > 0 ? variantId : 'انتخاب نشده'));
      $wrap.find('.dki-variation-dkp-title').text(title || 'محصولی انتخاب نشده است');
      $wrap.find('.dki-variation-dk-variant-title').text(variantTitle || 'رنگ/واریانت انتخاب نشده است');
    }

    $(document).on('click', '.dki-open-variation-modal', function () {
      const variationId = parseInt($(this).data('variation-id'), 10);
      if (!variationId) return;
      openModal(variationId);
    });

    $(document).on('click', '.dki-clear-variation-link', function () {
      const variationId = parseInt($(this).data('variation-id'), 10);
      if (!variationId) return;

      $.post(ajaxUrl, { action: 'dki_variation_link_product', nonce, variation_id: variationId, dkp: 0, dk_variant_id: 0 })
        .done(function (resp) {
          if (!resp || !resp.success) return alert(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');
          setVariationLinkUI(variationId, { dkp: 0, variant_id: 0, title: '', variant_title: '' });
        })
        .fail(function () { alert('خطای ارتباط با سرور.'); });
    });

    $modal.on('click', '.dki-modal-close, .dki-modal-backdrop', function () { closeModal(); });

    $('#dki-variation-search-btn').on('click', function () {
      const q = $('#dki-variation-search-query').val().trim();
      const $status = $('#dki-variation-search-status');
      const $results = $('#dki-variation-search-results');
      if (!q) return $status.text('عبارت جستجو را وارد کنید.');

      selectedProduct = null;
      selectedVariant = null;
      $('#dki-variation-next-btn').prop('disabled', true);
      $('#dki-variation-apply-btn').prop('disabled', true);
      $('#dki-variation-variant-status').text('');
      $('#dki-variation-variants-list').empty();

      $status.text('در حال جستجو...');
      $results.empty();

      $.post(ajaxUrl, { action: 'dki_variation_search_products', nonce, query: q })
        .done(function (resp) {
          if (!resp || !resp.success) return $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');

          const items = resp.data.results || [];
          if (!items.length) return $status.text('نتیجه‌ای پیدا نشد.');

          let html = '<div class="dkiux-table-wrap"><table class="dkiux-table"><thead><tr>';
          html += '<th>انتخاب</th><th>تصویر</th><th>محصول</th><th>قیمت</th></tr></thead><tbody>';
          items.forEach(function (item) {
            html += '<tr>';
            html += '<td><input type="radio" class="dkiux-check dki-variation-product-radio" name="dki_variation_product" value="' + parseInt(item.id || 0, 10) + '"></td>';
            html += '<td>' + (item.image ? '<img class="dki-thumb" src="' + esc(item.image) + '" alt="">' : '') + '</td>';
            html += '<td><div class="dkiux-strong">' + esc(item.title_fa || item.title_en || '') + '</div></td>';
            html += '<td>' + formatPrice(item.price) + '</td>';
            html += '</tr>';
          });
          html += '</tbody></table></div>';

          $results.html(html);
          $status.text('مرحله 1: یک محصول را انتخاب کنید.');
          $results.find('.dki-variation-product-radio').on('change', function () {
            const id = parseInt($(this).val(), 10);
            selectedProduct = items.find(function (it) { return parseInt(it.id, 10) === id; }) || null;
            $('#dki-variation-next-btn').prop('disabled', !selectedProduct);
          });
        })
        .fail(function () { $status.text('خطای ارتباط با سرور.'); });
    });

    $('#dki-variation-next-btn').on('click', function () {
      if (!selectedProduct) return;

      const $status = $('#dki-variation-variant-status');
      const $list = $('#dki-variation-variants-list');
      $status.text('در حال دریافت رنگ‌ها/واریانت‌ها...');
      $list.empty();
      $('#dki-variation-apply-btn').prop('disabled', true);

      $.post(ajaxUrl, { action: 'dki_variation_fetch_variants', nonce, dkp: parseInt(selectedProduct.id, 10) })
        .done(function (resp) {
          if (!resp || !resp.success) return $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');

          const variants = resp.data.variants || [];
          setStep(2);

          if (!variants.length) {
            $status.text('برای این محصول واریانتی یافت نشد.');
            return;
          }

          let html = '<div class="dkiux-table-wrap"><table class="dkiux-table"><thead><tr>';
          html += '<th>انتخاب</th><th>Variant ID</th><th>عنوان/رنگ</th><th>قیمت</th><th>وضعیت</th></tr></thead><tbody>';

          variants.forEach(function (v) {
            const vtitle = v.title || (v.color_title ? ('رنگ ' + v.color_title) : ('Variant #' + v.variant_id));
            html += '<tr>';
            html += '<td><input type="radio" class="dkiux-check dki-variation-variant-radio" name="dki_variation_variant" value="' + parseInt(v.variant_id || 0, 10) + '"></td>';
            html += '<td>' + parseInt(v.variant_id || 0, 10) + '</td>';
            html += '<td><div class="dkiux-strong">' + esc(vtitle) + '</div>' + (v.color_title ? '<div class="dkiux-mini">رنگ: ' + esc(v.color_title) + '</div>' : '') + '</td>';
            html += '<td>' + formatPrice(v.price) + '</td>';
            html += '<td>' + stockChip(v.stock_status) + '</td>';
            html += '</tr>';
          });

          html += '</tbody></table></div>';
          $list.html(html);
          $status.text('مرحله 2: واریانت دقیق (رنگ) را انتخاب کنید.');

          selectedVariant = null;
          $list.find('.dki-variation-variant-radio').on('change', function () {
            const vid = parseInt($(this).val(), 10);
            selectedVariant = variants.find(function (v) { return parseInt(v.variant_id, 10) === vid; }) || null;
            $('#dki-variation-apply-btn').prop('disabled', !(selectedProduct && selectedVariant));
          });
        })
        .fail(function () { $status.text('خطای ارتباط با سرور.'); });
    });

    $('#dki-variation-back-btn').on('click', function () {
      setStep(1);
      selectedVariant = null;
      $('#dki-variation-apply-btn').prop('disabled', true);
    });

    $('#dki-variation-apply-btn').on('click', function () {
      if (!activeVariationId || !selectedProduct || !selectedVariant) return;

      const $btn = $(this);
      $btn.prop('disabled', true).text('در حال اتصال...');

      $.post(ajaxUrl, {
        action: 'dki_variation_link_product',
        nonce,
        variation_id: activeVariationId,
        dkp: parseInt(selectedProduct.id, 10),
        dk_variant_id: parseInt(selectedVariant.variant_id, 10)
      }).done(function (resp) {
        if (!resp || !resp.success) return alert(resp && resp.data && resp.data.message ? resp.data.message : 'خطا');

        const d = resp.data || {};
        setVariationLinkUI(activeVariationId, {
          dkp: d.dkp || parseInt(selectedProduct.id, 10),
          variant_id: d.variant_id || parseInt(selectedVariant.variant_id, 10),
          title: d.title || selectedProduct.title_fa || selectedProduct.title_en || '',
          variant_title: d.variant_title || selectedVariant.title || selectedVariant.color_title || ''
        });

        closeModal();
      }).fail(function () {
        alert('خطای ارتباط با سرور.');
      }).always(function () {
        $btn.prop('disabled', false).text('تایید اتصال');
      });
    });
  }
});

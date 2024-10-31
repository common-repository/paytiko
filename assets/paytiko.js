jQuery('body').on('updated_checkout', () => {
    const $ = jQuery;
    if ($('#paytiko_container').length) return;
    const jqCont = $(
        '<div id="paytiko_container" style="position:fixed; top:0; left:0; bottom:0; right:0; z-index: 10000; background:rgba(40,40,40,0.5);">' +
        '   <div id="paytiko_ifr" style="position:absolute; top:0; bottom:0; left:0; right:0; padding:7px; border-radius:6px; margin:auto; width:720px; height:650px; background-color:white"></div>' +
        '   <div id="paytiko_close" style="position:absolute; cursor:pointer; color:white; border-radius: 12px; height:24px; width:24px; background-color:#555; font-weight:bold; font-size:22px; line-height:24px; text-align:center">&#215</div>' +
        '</div>'
    ).appendTo('body');
    const jqClose = $('#paytiko_close');
    const jqIfr = $('#paytiko_ifr');

    try {
        window.paytikoEcommerceSdk.renderCashier({
            containerSelector: '#paytiko_ifr',
            cashierUrl: paytikoCashierBaseUrl,
            sessionToken: paytikoSessionToken,
            locale: 'en-US'
        });
    } catch(err) {
        jqCont.hide();
        alert('Unable to render cashier:\n' + err);
        return;
    }

    function updateIfr() {
        const w = $(window).width(), h = $(window).height();
        const isMob = ((h > w ? w : h) <= 760);
        jqIfr.css({ width:(isMob ? '90%' : '720px'), height:(isMob ? '90%' : '650px')});
        const pos = jqIfr.offset();
        jqClose.css({
            left:parseInt(pos.left + jqIfr.width() - $(window).scrollLeft() + 6)+'px',
            top:parseInt(pos.top - $(window).scrollTop() - 12) + 'px'
        });
    }

    jqClose.click(() => { jqCont.hide() });
    window.addEventListener('resize', updateIfr, false);
    window.addEventListener('orientationchange', updateIfr, false);

    updateIfr();
});

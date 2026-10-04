/* REDCap showToast renders HTML. Treat all module feedback, including names, as plain text. */
window.PDFSealerNotify = (text, tone = 'success') => {
    const type = tone === 'danger' ? 'error' : tone;
    const escaped = String(text ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[character])).replace(/\r?\n/g, '<br>');
    window.showToast('PDF Sealer', escaped, type, type === 'warning' ? 12000 : 6000);
};

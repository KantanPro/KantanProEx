/**
 * 仕事リストの進捗プルダウン用のJavaScript
 */
document.addEventListener('DOMContentLoaded', function() {
    // 進捗プルダウンが変更されたときの処理
    const progressSelects = document.querySelectorAll('.progress-select');
    
    progressSelects.forEach(function(select) {
        // 初期値を保存
        select.dataset.originalValue = select.value;
        
        // 変更イベントの処理を追加
        select.addEventListener('change', function() {
            // 進捗状態に合わせたクラスを更新
            this.className = 'progress-select status-' + this.value;
        });
    });
});

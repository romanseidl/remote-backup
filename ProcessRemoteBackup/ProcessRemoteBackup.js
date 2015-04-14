/**
 * Test Connection Button
 *  
 */

$(document).ready(function() {
    if ($("#test-connection").size()) {
        var button = $("#test-connection");
        var url = button.attr('url');
        button.click(function() {
            button.find("i").addClass('fa-spin');
            $.ajax({
                type: 'POST',
                url: url,
                data: $('#edit-form').serialize(),
                success: function(data) {
                    data=$.parseJSON(data);
                    button.removeClass('ui-state-active')
                    button.find("i").removeClass('fa-spin');
                    var message = data['success'] ? "Connection successful" : "Error: " + data['error'];
                    var result = $('#backup-test-result');
                    if (!result.size())
                        result = button.parent().append("<span id='backup-test-result' title='a'>a</span>").find('#backup-test-result');
                    result.text(message);
                    result.tooltip({
                        content: data['log']
                    });
                }
            });
        });
    }
});

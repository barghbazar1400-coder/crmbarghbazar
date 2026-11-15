/* Custom TinyMCE 4 button for the CRM task-progress editor only.
   Tables are HTML entered into the private, server-sanitized task history. */
(function () {
    "use strict";
    if (!window.tinymce || !window.tinymce.PluginManager) return;

    window.tinymce.PluginManager.add("crmbtasktable", function (editor) {
        function tableHtml(rowCount, columnCount) {
            var headers = "";
            var rows = "";
            for (var col = 1; col <= columnCount; col++) {
                headers += "<th>ستون " + col + "</th>";
            }
            for (var row = 0; row < rowCount; row++) {
                rows += "<tr>";
                for (var cell = 0; cell < columnCount; cell++) {
                    rows += "<td>متن</td>";
                }
                rows += "</tr>";
            }
            return "<table><thead><tr>" + headers + "</tr></thead><tbody>" +
                rows + "</tbody></table><p></p>";
        }

        editor.addButton("crmbtasktable", {
            text: "جدول",
            tooltip: "درج جدول در گزارش پیشرفت",
            onclick: function () {
                editor.windowManager.open({
                    title: "درج جدول",
                    body: [
                        { type: "textbox", name: "rows", label: "تعداد ردیف", value: "3" },
                        { type: "textbox", name: "columns", label: "تعداد ستون", value: "3" }
                    ],
                    onsubmit: function (event) {
                        var rows = Number(event.data.rows);
                        var columns = Number(event.data.columns);
                        if (!Number.isInteger(rows) || !Number.isInteger(columns) ||
                            rows < 1 || rows > 10 || columns < 1 || columns > 8) {
                            editor.windowManager.alert("ردیف باید بین ۱ تا ۱۰ و ستون بین ۱ تا ۸ باشد.");
                            return false;
                        }
                        editor.focus();
                        editor.insertContent(tableHtml(rows, columns));
                    }
                });
            }
        });
    });
})();

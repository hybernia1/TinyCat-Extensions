(function () {
  "use strict";

  var templates = {
    paragraph: ["<p>", "</p>"],
    heading: ["<h2>", "</h2>"],
    strong: ["<strong>", "</strong>"],
    emphasis: ["<em>", "</em>"],
    list: ["<ul>\n  <li>", "</li>\n</ul>"],
    quote: ["<blockquote><p>", "</p></blockquote>"],
    code: ["<pre><code>", "</code></pre>"]
  };

  function insert(textarea, before, after, fallback) {
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var selected = textarea.value.slice(start, end) || fallback;
    textarea.setRangeText(before + selected + after, start, end, "end");
    textarea.focus();
    textarea.dispatchEvent(new Event("input", { bubbles: true }));
  }

  document.addEventListener("click", function (event) {
    var button = event.target.closest("[data-html-editor-action]");
    if (!button) return;

    var editor = button.closest("[data-html-editor]");
    var inputId = editor && editor.getAttribute("data-html-editor-target");
    var textarea = inputId ? document.getElementById(inputId) : null;
    if (!textarea) return;

    event.preventDefault();
    var action = button.getAttribute("data-html-editor-action");
    if (action === "link") {
      var url = window.prompt("URL", "https://");
      if (url === null || url.trim() === "") return;
      insert(textarea, '<a href="' + url.trim().replace(/"/g, "&quot;") + '">', "</a>", "Link text");
      return;
    }

    var template = templates[action];
    if (template) insert(textarea, template[0], template[1], "Text");
  });
}());

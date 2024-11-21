(()=>{var e={923:e=>{e.exports=function(e){var s,n,t,o,a,r=this;function i(){switch(_Options.position){case"bl":return"js-snackbar-container--bottom-left";case"tl":return"js-snackbar-container--top-left";case"tr":return"js-snackbar-container--top-right";case"tc":case"tm":return"js-snackbar-container--top-center";case"bc":case"bm":return"js-snackbar-container--bottom-center";default:return"js-snackbar-container--bottom-right"}}this.Open=function(){var e=function(){const e=window.getComputedStyle(a);return o.scrollHeight+parseFloat(e.getPropertyValue("padding-top"))+parseFloat(e.getPropertyValue("padding-bottom"))}();n.style.height=e+"px",n.style.opacity=1,n.style.marginTop="5px",n.style.marginBottom="5px",n.addEventListener("transitioned",(function(){n.removeEventListener("transitioned",arguments.callee),n.style.height=null}))},this.Close=function(){s&&clearInterval(s);var e=n.scrollHeight,o=n.style.transition;n.style.transition="",requestAnimationFrame((function(){n.style.height=e+"px",n.style.opacity=1,n.style.marginTop="0px",n.style.marginBottom="0px",n.style.transition=o,requestAnimationFrame((function(){n.style.height="0px",n.style.opacity=0}))})),setTimeout((function(){t.removeChild(n)}),1e3)},_Options={message:e?.message??"Operation performed successfully.",dismissible:e?.dismissible??!0,timeout:e?.timeout??5e3,status:e?.status?e.status.toLowerCase().trim():"",actions:e?.actions??[],fixed:e?.fixed??!1,position:e?.position??"br",container:e?.container??document.body,width:e?.width,speed:e?.speed,icon:e?.icon},function(){var e=o();function s(e){for(var s,t=i(),o=0;o<e.children.length;o++)if(1===(s=e.children.item(o)).nodeType&&s.classList.length>0&&s.classList.contains("js-snackbar-container")&&s.classList.contains(t))return s;return n(e)}function n(e){var s=document.createElement("div");return s.classList.add("js-snackbar-container"),_Options.fixed&&s.classList.add("js-snackbar-container--fixed"),e.appendChild(s),s}function o(){return"string"==typeof _Options.container?document.querySelector(_Options.container):_Options.container}void 0===e&&(console.warn("SnackBar: Could not find target container "+_Options.container),e=document.body),t=s(e)}(),function(){t.classList.add(i());var e="js-snackbar-container--fixed";_Options.fixed?t.classList.add(e):t.classList.remove(e)}(),n=function(){var e=n(),s=t();return e.appendChild(s),e;function n(){var e=document.createElement("div");return e.classList.add("js-snackbar__wrapper"),e.style.height="0px",e.style.opacity="0",e.style.marginTop="0px",e.style.marginBottom="0px",p(e),u(e),e}function t(){var e=document.createElement("div");return e.classList.add("js-snackbar","js-snackbar--show"),i(e),c(e),d(e),l(e),e}function i(e){if(_Options.status){var s=document.createElement("span");s.classList.add("js-snackbar__status"),n(s),t(s),e.appendChild(s)}function n(e){switch(_Options.status){case"success":case"green":e.classList.add("js-snackbar--success");break;case"warning":case"alert":case"orange":e.classList.add("js-snackbar--warning");break;case"danger":case"error":case"red":e.classList.add("js-snackbar--danger");break;default:e.classList.add("js-snackbar--info")}}function t(e){if(_Options.icon){var s=document.createElement("span");switch(s.classList.add("js-snackbar__icon"),_Options.icon){case"exclamation":case"warn":case"danger":s.innerText="!";break;case"info":case"question":case"question-mark":s.innerText="?";break;case"plus":case"add":s.innerText="+";break;default:_Options.icon.length>1&&console.warn("Invalid icon character provided: ",_Options.icon),s.innerText=_Options.icon.substr(0,1)}e.appendChild(s)}}}function c(e){(a=document.createElement("div")).classList.add("js-snackbar__message-wrapper"),(o=document.createElement("span")).classList.add("js-snackbar__message"),o.innerHTML=_Options.message,a.appendChild(o),e.appendChild(a)}function d(e){if("object"==typeof _Options.actions)for(var s=0;s<_Options.actions.length;s++)n(e,_Options.actions[s]);function n(e,s){var n=document.createElement("span");n.classList.add("js-snackbar__action"),n.textContent=s.text,"function"==typeof s.function?!0===s.dismiss?n.onclick=function(){s.function(),r.Close()}:n.onclick=s.function:n.onclick=r.Close,e.appendChild(n)}}function l(e){if(_Options.dismissible){var s=document.createElement("span");s.classList.add("js-snackbar__close"),s.innerText="×",s.onclick=r.Close,e.appendChild(s)}}function p(e){_Options.width&&(e.style.width=_Options.width)}function u(e){const{speed:s}=_Options;switch(typeof s){case"number":e.style.transitionDuration=s+"ms";break;case"string":e.style.transitionDuration=s}}}(),t.appendChild(n),!1!==_Options.timeout&&_Options.timeout>0&&(s=setTimeout(r.Close,_Options.timeout)),r.Open()}}},s={};function n(t){var o=s[t];if(void 0!==o)return o.exports;var a=s[t]={exports:{}};return e[t](a,a.exports,n),a.exports}n.n=e=>{var s=e&&e.__esModule?()=>e.default:()=>e;return n.d(s,{a:s}),s},n.d=(e,s)=>{for(var t in s)n.o(s,t)&&!n.o(e,t)&&Object.defineProperty(e,t,{enumerable:!0,get:s[t]})},n.o=(e,s)=>Object.prototype.hasOwnProperty.call(e,s),(()=>{"use strict";const e=async function(e,s,n){let t=arguments.length>3&&void 0!==arguments[3]?arguments[3]:"";const o={"X-WP-Nonce":s};let a=null,r=!1;t instanceof FormData?(r=!0,a=t,a.append("action",n)):(a="action="+n+t,o["Content-Type"]="application/x-www-form-urlencoded;charset=UTF-8");const i=await fetch(e,{method:"POST",credentials:"same-origin",headers:o,body:a});return 200!==i.status?{success:!1,text:"Unknown error occurred. Please contact support if the issue persists."}:i.json()};var s=n(923),t=n.n(s);const o=e=>{const s=jQuery(".superbaddons-admindashboard-modal-wrapper"),n=s.find(".superbaddons-admindashboard-modal-overlay"),t=s.find(".superbaddons-admindashboard-modal-close-button"),o=s.find(".superbaddons-admindashboard-modal-confirm-btn"),a=s.find(".superbaddons-admindashboard-modal-cancel-btn"),r=s.find(".superbaddons-admindashboard-modal-ok-btn"),i=s.find(".superbaddons-admindashboard-modal-title"),c=s.find(".superbaddons-admindashboard-modal-content"),d=s.find(".superbaddons-admindashboard-modal-header-spinner"),l=s.find(".superbaddons-admindashboard-modal-footer"),p=s.find(".superbaddons-element-separator"),u=e.title||!1,b=e.copyable||!1,m=e.content||!1,k=e.confirmCallback||!1,f=e.cancelCallback||!1,g=e.okCallback||!1,h=e.awaitConfirmCallback||!1,w=e.stopCloseOnConfirm||!1,v=e.preventClosing||!1,y=e.isLoader||!1,C=function(){let e=arguments.length>0&&void 0!==arguments[0]&&arguments[0];o.off(),a.off(),t.off(),n.off(),r.off(),jQuery(window).off("keydown.superbaddons-admin-modal"),e?s.hide():s.fadeOut("fast")};return((e,u,m,k,f,g,h,v)=>{if(h?(t.off(),n.off(),jQuery(window).off("keydown.superbaddons-admin-modal"),t.hide()):(jQuery(window).on("keydown.superbaddons-admin-modal",(e=>{27==(e.keyCode||e.which)&&C()})),t.on("click",(()=>{C()})),n.on("click",(()=>{C()}))),v)return d.show(),i.hide(),l.hide(),t.hide(),p.hide(),c.text(u),void s.fadeIn("fast");if(d.hide(),l.show(),i.show(),h||t.show(),p.show(),e&&i.text(e),u)if(Array.isArray(u))c.text(""),u.map((e=>{const s=jQuery("<div class='superbaddons-admindashboard-modal-content-item'></div>"),n=jQuery("<div class='superbaddons-admindashboard-modal-content-item-title'></div>"),t=jQuery("<div class='superbaddons-admindashboard-modal-content-item-content'></div>"),o=jQuery("<img class='superbaddons-admindashboard-modal-content-item-icon' />");superbaddonssettings_g&&(o.attr("src",e.shared?superbaddonssettings_g.modal.view_logs.icon_shared:superbaddonssettings_g.modal.view_logs.icon_unshared),o.attr("title",e.shared?superbaddonssettings_g.modal.view_logs.shared_title:superbaddonssettings_g.modal.view_logs.unshared_title));const a=new Date(1e3*e.time),r=a.toLocaleDateString()+" "+a.toLocaleTimeString();n.text(r+" (v."+e.version+"): "+e.title),n.prepend(o),t.text(e.stack),s.append(n),s.append(t),c.append(s)}));else if(b){c.text("");const e=jQuery('<textarea class="superbaddons-admindashboard-modal-copyable-item" onclick="this.focus();this.select()" readonly="readonly"></textarea>'),s=jQuery('<div class="superbaddons-element-text-sm superbaddons-admindashboard-modal-copyable-explainer"></div>');s.text(b),e.text(u),c.append(e),c.append(s)}else c.text(u);m?(o.show(),a.show(),r.hide(),o.on("click",(async()=>{g?await m():m(),w||C()}))):(o.hide(),a.hide(),r.show(),r.on("click",(()=>{f&&f(),w||C()}))),k?a.on("click",(()=>{k(),C()})):a.on("click",(()=>{C()})),s.fadeIn("fast")})(u,m,k,f,g,h,v,y),C},a={Issues:{LintErrors:!1,UnsafeCSS:!1},Options:{UnsafeAllowed:!1},CurrentBlockID:!1,UnsavedChanges:{InitialValue:"",InitialName:"",HasUnsavedName:!1,HasUnsavedChanges:!1}},r=()=>{window.SuperbAddonsCSSCurrentBlockID=a.CurrentBlockID},i=async function(s,n){let a=arguments.length>2&&void 0!==arguments[2]&&arguments[2];if(!n||!n.length)return;const{__:r}=wp.i18n,i=1===n.length,c=r(i?"Delete CSS Block":"Delete CSS Blocks","superb-blocks"),d=r(i?"Are you sure you want to delete this CSS Block?":"Are you sure you want to delete the selected CSS Blocks?","superb-blocks"),l=r(i?"Deleting CSS Block...":"Deleting CSS Blocks...","superb-blocks");o({stopCloseOnConfirm:!0,title:c,content:d,confirmCallback:async function(){const s=o({preventClosing:!0,isLoader:!0,content:l}),r={block_ids:n},i=await e(superbaddonscssblocks_g.rest.base+superbaddonscssblocks_g.rest.namespace+superbaddonscssblocks_g.rest.routes.css,superbaddonscssblocks_g.rest.nonce,"delete-blocks","&"+new URLSearchParams(r).toString());if(!i.success)return t()({message:i.text?i.text:superbaddonscssblocks_g.rest.error_message,timeout:8e3,position:"bl",status:"error",fixed:!0,icon:"danger",container:"#wpcontent"}),void s();setTimeout((()=>{a?window.location=a:window.location.reload()}),500)}})},c=async(s,n)=>{if(!n||!n.length)return;const{__:a}=wp.i18n,r=1===n.length,i=o({preventClosing:!0,isLoader:!0,content:a(r?"Deactivating CSS Block...":"Deactivating CSS Blocks...","superb-blocks")}),c={block_ids:n},d=await e(superbaddonscssblocks_g.rest.base+superbaddonscssblocks_g.rest.namespace+superbaddonscssblocks_g.rest.routes.css,superbaddonscssblocks_g.rest.nonce,"deactivate-blocks","&"+new URLSearchParams(c).toString());if(!d.success)return t()({message:d.text?d.text:superbaddonscssblocks_g.rest.error_message,timeout:8e3,position:"bl",status:"error",fixed:!0,icon:"danger",container:"#wpcontent"}),void i();setTimeout((()=>{window.location.reload()}),500)},d=async(s,n)=>{if(!n||!n.length)return;const{__:a}=wp.i18n,r=1===n.length,i=o({preventClosing:!0,isLoader:!0,content:a(r?"Activating CSS Block...":"Activating CSS Blocks...","superb-blocks")}),c={block_ids:n},d=await e(superbaddonscssblocks_g.rest.base+superbaddonscssblocks_g.rest.namespace+superbaddonscssblocks_g.rest.routes.css,superbaddonscssblocks_g.rest.nonce,"activate-blocks","&"+new URLSearchParams(c).toString());if(!d.success)return t()({message:d.text?d.text:superbaddonscssblocks_g.rest.error_message,timeout:8e3,position:"bl",status:"error",fixed:!0,icon:"danger",container:"#wpcontent"}),void i();setTimeout((()=>{window.location.reload()}),500)},{__:l}=wp.i18n,p=s=>{let n=!1;const p=s(".superbaddons-css-block-save-options .superbaddons-css-block-cancel-btn").attr("href"),f=new URLSearchParams(window.location.search);if(a.CurrentBlockID=f.get("css-edit"),r(),superbaddonscssblocks_g&&superbaddonscssblocks_g.codeEditorSettings&&wp.codeEditor){n=wp.codeEditor.initialize("superbaddons-css-block-css-input",superbaddonscssblocks_g.codeEditorSettings),s("label[for='superbaddons-css-block-css-input']").on("click",(function(){s(".superbaddons-css-block-css-input-wrapper .CodeMirror .CodeMirror-code").trigger("focus")})),a.UnsavedChanges.InitialValue=n.codemirror.getValue();s(".superbaddons-css-block-css-input-wrapper .CodeMirror .CodeMirror-code").on("keyup",(function(){const e=n.codemirror.getValue();a.UnsavedChanges.HasUnsavedChanges=e!==a.UnsavedChanges.InitialValue,h()}))}n&&(((e,s)=>{const n=e("#superbaddons-css-block-live-preview iframe").data("src"),t="keydown.superbaddons.escapetoclose",o={Opened:!1,Wrapper:e("#superbaddons-css-block-live-preview"),Spinner:e("#superbaddons-css-block-live-preview .superbaddons-spinner-wrapper"),Container:e("#superbaddons-css-block-live-preview .superbaddons-css-block-live-preview-container"),iFrame:{Frame:e("#superbaddons-css-block-live-preview iframe"),PreviewFrame:e("#superbaddons-css-block-live-preview iframe"),Head:!1,PreviewHead:!1,PreviewStyleTag:!1},WPPreviewer:!1,PreviewCSSInputWrapper:e("#superbaddons-css-block-live-preview .superbaddons-css-block-preview-input"),CSSInputWrapper:e(".superbaddons-css-block-css-input-wrapper"),Buttons:{Close:e("#superbaddons-css-block-live-preview .superbaddons-live-preview-close-btn"),ReloadButton:e("#superbaddons-preview-reload-button")}};o.Buttons.Close.on("click",(function(){o.Opened&&(o.Opened=!1,o.Wrapper.fadeOut("fast",(function(){e(".superbaddons-css-block-css-input-wrapper-outer").append(o.CSSInputWrapper)})))})),o.Buttons.ReloadButton.on("click",(function(){o.WPPreviewer&&o.WPPreviewer.refresh()})),e(".superbaddons-css-block-preview-btn").on("click",(function(){o.Wrapper.fadeIn("fast"),o.PreviewCSSInputWrapper.append(o.CSSInputWrapper),o.iFrame.Frame.attr("src")?a():(o.iFrame.Frame.on("load",(async function(){o.iFrame.Head=o.iFrame.Frame.contents().find("head"),e(o.iFrame.Head).append('<style type="text/css">#customize-preview, #customize-preview * {display:block !important;} body * {display:none !important;}</style>'),(await(async()=>new Promise((e=>{const s=setInterval((()=>{const n=o.iFrame.Frame.contents().find("body");if(!n.length)return;const t=n.find("#customize-preview");if(!t.length)return;const a=t.find("iframe");a.length&&(n.append(t),clearInterval(s),e(a))}),500)})))()).on("load",(function(){r(),a(),setInterval((()=>{r()}),100),o.Spinner.fadeOut("fast")}))})),o.iFrame.Frame.attr("src",n))}));const a=()=>{o.Opened=!0,jQuery(window).off(t),jQuery(window).on(t,(e=>{27==(e.keyCode||e.which)&&o.Buttons.Close.trigger("click")}))},r=async()=>{if(!s)return;if(!o.iFrame.Frame[0].contentWindow.wp.customize.previewer)return;if(!o.iFrame.Frame[0].contentWindow.wp.customize.previewer.preview)return;if(!o.iFrame.Frame[0].contentWindow.wp.customize.previewer.preview.iframe)return;o.WPPreviewer=o.iFrame.Frame[0].contentWindow.wp.customize.previewer;const n=o.iFrame.Frame[0].contentWindow.wp.customize.previewer.preview.iframe;o.iFrame.PreviewFrame&&n[0]==o.iFrame.PreviewFrame[0]||(o.iFrame.PreviewFrame.remove("#superbaddons-css-blocks-live-preview-inline-css"),o.iFrame.PreviewFrame=n,o.iFrame.PreviewHead=o.iFrame.PreviewFrame.contents().find("head"),o.iFrame.PreviewStyleTag=e('<style id="superbaddons-css-blocks-live-preview-inline-css" type="text/css"></style>'),o.iFrame.PreviewFrame.remove("#superbaddons-css-blocks-live-preview-inline-css"),e(o.iFrame.PreviewHead).append(o.iFrame.PreviewStyleTag));const t=s.codemirror.getValue();o.iFrame.PreviewStyleTag.text()!=t&&o.iFrame.PreviewStyleTag.text(t)}})(s,n),n.settings.onChangeLintingErrors=function(e,n,t,o){e.length?(a.Issues.LintErrors=!0,s(".superbaddons-css-block-errors-wrapper").show()):(a.Issues.LintErrors=!1,s(".superbaddons-css-block-errors-wrapper").hide()),k(o),h()},n.codemirror.setOption("lint",!0),m(n),k(n.codemirror),s(".superbaddons-css-block-save-btn").on("click",(async function(i){if(n.codemirror.setOption("lint",!0),m(n),k(n.codemirror),h(),!g())return void i.preventDefault();const c={name:s("#superbaddons-css-block-name-input").val(),css:n.codemirror.getValue(),selectors:b()},d={block:JSON.stringify(c)};a.CurrentBlockID&&(d.id=a.CurrentBlockID);const u=o({preventClosing:!0,isLoader:!0,content:l("Saving CSS Block...","superb-blocks")}),f=await e(superbaddonscssblocks_g.rest.base+superbaddonscssblocks_g.rest.namespace+superbaddonscssblocks_g.rest.routes.css,superbaddonscssblocks_g.rest.nonce,"save-block","&"+new URLSearchParams(d).toString());f.success?(w(n),a.CurrentBlockID=f.id,r(),window.history.replaceState({},"",p+"&css-edit="+a.CurrentBlockID),h(),setTimeout((()=>{t()({message:l("Your CSS Block has been saved.","superb-blocks"),timeout:8e3,position:"bl",status:"success",fixed:!0,icon:"✓",container:"#wpcontent"}),s(".superbaddons-created-css-block-options").show(),u()}),500)):t()({message:f.text?f.text:superbaddonscssblocks_g.rest.error_message,timeout:8e3,position:"bl",status:"error",fixed:!0,icon:"danger",container:"#wpcontent"})})),s(".superbaddons-css-block-delete-btn").on("click",(function(){i(s,[a.CurrentBlockID],p)})),s(".superbaddons-deactivate-blocks-btn").on("click",(function(){c(0,[a.CurrentBlockID])})),s(".superbaddons-activate-blocks-btn").on("click",(function(){d(0,[a.CurrentBlockID])}))),"undefined"!=typeof superbaddons_init_css_block_selectors&&u(superbaddons_init_css_block_selectors);const v=s(".superbaddons-element-select");v&&v.each((function(){const e=s(this);e.select2({placeholder:e.attr("placeholder")})})),s("#superbaddons-css-block-allow-unsafe-css").on("change",(function(){a.Options.UnsafeAllowed=s(this).prop("checked"),h()})),s("#superbaddons-css-block-allow-unsafe-css").trigger("change"),a.UnsavedChanges.InitialName=s("#superbaddons-css-block-name-input").val(),s("#superbaddons-css-block-name-input").on("keyup",(function(){a.UnsavedChanges.HasUnsavedName=s(this).val()!==a.UnsavedChanges.InitialName,h()})),s(".superbaddons-inputcheckbox-input").on("change",(function(){const e=s(this),n=e.is("#superbaddons-css-block-target-input-website")?!e.prop("checked"):e.prop("checked"),t=e.parents(".superb-addons-checkbox-input-wrapper").next(".superbaddons-element-specific-select-wrapper");t.length&&(n?t.slideDown():t.slideUp())})),s(".superbaddons-inputcheckbox-input").trigger("change"),s(".superbaddons-inputcheckbox-input").on("change",(function(){a.UnsavedChanges.HasUnsavedChanges=!0,h()})),v.on("change",(function(){a.UnsavedChanges.HasUnsavedChanges=!0,h()}))},u=e=>{if(!e)return;let s=!1;e.forEach((e=>{if("full"===e.type)return void(s=!0);const n=jQuery(`.superbaddons-inputcheckbox-input[data-action='${e.type}']`);if(n.prop("checked",!0),e.value){n.parents(".superb-addons-checkbox-input-wrapper").next(`.superbaddons-element-specific-select-wrapper [data-type='${e.type}']`).find("select").val(e.value)}})),s||jQuery(".superbaddons-inputcheckbox-input[data-action='full']").prop("checked",!1)},b=()=>{const e=[];return jQuery(".superbaddons-inputcheckbox-input").each((function(){const s=jQuery(this);if(!s.prop("checked"))return;const n=s.data("action"),t=s.parents(".superb-addons-checkbox-input-wrapper").next(`.superbaddons-element-specific-select-wrapper [data-type='${n}']`),o={type:n,value:!!t.length&&t.find("select").val()};e.push(o)})),e},m=e=>!!e?.codemirror?.state?.lint?.marked?.length&&(e.settings.onChangeLintingErrors([{}]),!0),k=e=>{e&&(a.Issues.UnsafeCSS=f(e),a.Issues.UnsafeCSS?jQuery(".superbaddons-css-block-allow-unsafe-css-wrapper").show():jQuery(".superbaddons-css-block-allow-unsafe-css-wrapper").hide())},f=e=>{const s=e.getValue(),n=[/@import\s/gi,/url\([^\)]+\)/gi,/@font-face/gi,/expression\(/gi,/behavior:/gi,/-moz-binding:/gi,/-webkit-binding:/gi,/url\s*\(['"]?javascript:/gi];for(const e of n)if(e.test(s))return!0;return!1},g=()=>(a.UnsavedChanges.HasUnsavedName||a.UnsavedChanges.HasUnsavedChanges)&&!a.Issues.LintErrors&&(!a.Issues.UnsafeCSS||a.Options.UnsafeAllowed),h=()=>{g()?(jQuery(".superbaddons-css-block-save-btn").prop("disabled",!1),jQuery(".superbaddons-toggle-activate-blocks-btn").prop("disabled",!0)):(jQuery(".superbaddons-css-block-save-btn").prop("disabled",!0),jQuery(".superbaddons-toggle-activate-blocks-btn").prop("disabled",a.UnsavedChanges.HasUnsavedChanges)),a.UnsavedChanges.HasUnsavedChanges?jQuery(".superbaddons-toggle-activate-blocks-btn").attr("title",l("You have unsaved changes in your CSS block.","superb-blocks")):jQuery(".superbaddons-toggle-activate-blocks-btn").attr("title","")},w=e=>{a.UnsavedChanges.HasUnsavedChanges=!1,a.UnsavedChanges.InitialValue=e.codemirror.getValue()},v=()=>{const e=jQuery(".superbaddons-css-block-item .superbaddons-inputcheckbox-input:checked");return!!e.length&&e.map((function(){return jQuery(this).closest(".superbaddons-css-block-item").data("id")})).get()};jQuery(document).ready((function(s){(e=>{e(".superbaddons-css-block-css-input-wrapper").length&&p(e)})(s),(s=>{const{__:n}=wp.i18n,a=s(".superbaddons-import-blocks-btn");a.length&&a.on("click",(function(){s("#superbaddons-import-blocks-file").attr("readonly",!1).prop("disabled",!1),s("#superbaddons-import-blocks-file").off(),s("#superbaddons-import-blocks-file").on("change",(async function(){s("#superbaddons-import-blocks-file").trigger("blur").attr("readonly",!0).prop("disabled",!0);const a=o({preventClosing:!0,isLoader:!0,content:n("Importing CSS Blocks...","superb-blocks")}),r=s("#superbaddons-import-blocks-file").get(0).files,i=new FormData;for(const e of r)i.append("files[]",e);const c=await e(superbaddonscssblocks_g.rest.base+superbaddonscssblocks_g.rest.namespace+superbaddonscssblocks_g.rest.routes.css,superbaddonscssblocks_g.rest.nonce,"import-blocks",i);await new Promise((e=>setTimeout(e,1e3))),c.success?o({title:n("Import Complete","superb-blocks"),content:c.text,preventClosing:!0,okCallback:()=>{window.location.reload()}}):(t()({message:c.text?c.text:n("Something went wrong during and the import could not complete.","superb-blocks"),timeout:8e3,position:"bl",status:"error",fixed:!0,icon:"danger",container:"#wpcontent"}),a())})),s("#superbaddons-import-blocks-file").trigger("click")}))})(s),(e=>{const{__:s}=wp.i18n,n=e("#superbaddons-css-block-select-all");if(!n.length)return;const t=e(".superbaddons-css-blocks-list-options button.superbaddons-element-button");function o(){const s=e(".superbaddons-css-block-item .superbaddons-inputcheckbox-input").filter(":checked").length>0;t.prop("disabled",!s)}t.prop("disabled",!0),n.on("change",(function(){const s=n.prop("checked");e(".superbaddons-css-block-checkbox").prop("checked",s),e(".superbaddons-css-block-item .superbaddons-inputcheckbox-input").prop("checked",s),o()})),e(".superbaddons-css-block-item .superbaddons-inputcheckbox-input").on("change",(function(){const s=e(".superbaddons-css-block-item .superbaddons-inputcheckbox-input"),t=s.filter(":checked").length===s.length;n.prop("checked",t),o()})),e(".superbaddons-delete-blocks-btn").on("click",(function(){i(e,v())})),e(".superbaddons-deactivate-blocks-btn").on("click",(function(){c(0,v())})),e(".superbaddons-activate-blocks-btn").on("click",(function(){d(0,v())}))})(s)}))})()})();
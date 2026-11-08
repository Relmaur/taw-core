var e,t,n,r,i,a,o,s,c,l,u={},d=[],f=/^m(i|n|o|s|text|space)$/,p=Array.isArray,m=d.slice,h=Object.assign;function g(e){e&&e.parentNode&&e.remove()}function _(n,r,i,a,o){var s={type:n,props:r,key:i,ref:a,__k:null,__:null,__b:0,__e:null,__c:null,constructor:void 0,__v:o||++t,__i:-1,__u:0};return!o&&e.vnode&&e.vnode(s),s}function v(e){return e.children}function y(e,t){this.props=e,this.context=t,this.__g=0}function b(e,t){if(t==null)return e.__?b(e.__,e.__i+1):null;for(var n;t<e.__k.length;t++)if((n=e.__k[t])&&n.__e)return n.__e;return typeof e.type!=`function`||e.props.__P?null:b(e)}function x(e){if((e=e.__)&&e.__c&&!e.props.__P)return e.__e=null,e.__k.some(function(t){return t&&(e.__e=t.__e)}),x(e)}function S(t){(8&t.__g||!(t.__g|=8)||!n.push(t)||i++)&&r==e.debounceRendering||((r=e.debounceRendering)||queueMicrotask)(C)}function C(){var t,r,o,s,c,l,u,d,f;try{for(r=1;n.length;)n.length>r&&n.sort(a),t=n.shift(),r=n.length,8&t.__g&&(s=void 0,c=void 0,l=(c=(o=t).__v).__e,u=[],d=[],(f=o.__P)&&((s=h({constructor:void 0},c)).__v=c.__v+1,e.vnode&&e.vnode(s),A(f,s,c,o.__n,f.namespaceURI,32&c.__u?[l]:null,u,l||b(c),32&c.__u,d),s.__v=c.__v,s.__.__k[s.__i]=s,ne(u,s,d),c.__=c.__e=null,s.__e!=l&&x(s)))}finally{n.length=i=0}}function ee(e,t,n,r,i,a,o,s,c,l,f){var p,m,h,g,_,v,y=r.__k||d,b=t.length;for(c=w(n,t,y,c,b),p=0;p<b;p++)(h=n.__k[p])!=null&&(m=~h.__i&&y[h.__i]||u,h.__i=p,v=A(e,h,m,i,a,o,s,c,l,f),g=h.__e,(m.ref!=h.ref||8&m.__u)&&(m.ref&&ie(m.ref,null,h,m),h.ref&&f.push(h.ref,h.__c||g,h)),_||=g,4&h.__u?(c=T(h,c,e,!m.__v),m.__e&&(m.__e=null)):typeof h.type==`function`&&v!==void 0?c=v:g&&(c=g.nextSibling),h.__u&=-7);return n.__e=_,c}function w(e,t,n,r,i){var a,o,s,c,l,u,d,f,m,h,g=n.length,y=g,x=0,S=!1,C=e.__k=Array(i);for(a=0;a<i;a++)(o=t[a])!=null&&typeof o!=`boolean`&&typeof o!=`function`?(typeof o!=`object`||o.constructor==String?o=C[a]=_(null,o):p(o)?o=C[a]=_(v,{children:o}):o.constructor===void 0&&o.__b?o=C[a]=_(o.type,o.props,o.key,o.ref,o.__v):C[a]=o,c=a+x,o.__=e,o.__b=e.__b+1,s=null,~(l=o.__i=E(o,n,c,y))&&(y--,(s=n[l])&&(s.__u|=2)),s&&s.__v?(o.__u|=2,l==c-1?x--:l==c+1?x++:l!=c&&(l>c?x--:x++,S=!0)):(~l||(i>g?x--:i<g&&x++),typeof o.type!=`function`&&(o.__u|=4))):C[a]=null;if(S){for(u=[],d=[],a=0;a<i;a++)if((o=C[a])&&2&o.__u){for(f=0,m=u.length;f<m;)u[h=f+m>>1]<o.__i?f=h+1:m=h;u[f]=o.__i,d[a]=f+1}for(x=u.length;a--;)d[a]&&(d[a]==x?x--:C[a].__u|=4)}if(y)for(a=0;a<g;a++)!(s=n[a])||2&s.__u||(s.__e==r&&(r=b(s)),ae(s,s));return r}function T(e,t,n,r){var i,a,o;if(typeof e.type==`function`){if(e.props.__P)return t;if(i=e.__k)for(a=0;a<i.length;a++)i[a]&&(i[a].__=e,t=T(i[a],t,n,!1));return t}for(t&&!t.parentNode&&(t=b(e))&&!t.parentNode&&(t=null),o=t;o&&o.nodeType==8;)o=o.nextSibling;for(e.__e!=o&&(!r&&n.moveBefore&&e.__e.parentNode?n.moveBefore(e.__e,t):n.insertBefore(e.__e,t||null)),t=e.__e;(t&&=t.nextSibling)&&t.nodeType==8;);return t}function E(e,t,n,r){var i,a,o,s=e.key,c=e.type,l=t[n],u=l&&!(2&l.__u);if(l===null&&s==null||u&&s==l.key&&c==l.type)return n;if(r>+!!u){for(i=n-1,a=n+1;i>=0||a<t.length;)if((l=t[o=i>=0?i--:a++])&&!(2&l.__u)&&s==l.key&&c==l.type)return o}return-1}function D(e,t,n){n??=``,t[0]==`-`?e.setProperty(t,n):e[t]=n}function O(e,t,n,r,i){var a,o;n:if(t==`style`){if(typeof n==`string`)e.style.cssText=n;else{if(typeof r==`string`&&(e.style.cssText=r=``),r)for(t in r)n&&t in n||D(e.style,t,``);if(n)for(t in n)r&&n[t]==r[t]||D(e.style,t,n[t])}}else if(t[0]==`o`&&t[1]==`n`)(e.__e||={})[t]=n,n&&r||(o=l[t]||(l[t]=k(t)),(e.__a||={})[t]=c,a=t!=(t=t.replace(s,`$1`)),(t=t.slice(2))[0]<`a`&&(t=t.toLowerCase()),n?e.addEventListener(t,o,a):e.removeEventListener(t,o,a));else{if(i==`http://www.w3.org/2000/svg`)t=t.replace(/xlink(H|:h)/,`h`).replace(/sName$/,`s`);else if(t!=`width`&&t!=`height`&&t!=`href`&&t!=`list`&&t!=`form`&&t!=`tabIndex`&&t!=`download`&&t!=`rowSpan`&&t!=`colSpan`&&t!=`role`&&t!=`popover`&&t in e)try{e[t]=n??``;break n}catch{}typeof n==`function`||(n==null||!1===n&&t[4]!=`-`?e.removeAttribute(t):e.setAttribute(t,t==`popover`&&n==1?``:n))}}function k(t){return function(n){if(this.__e){var r=this.__e[t];if(n[o]==null)n[o]=c++;else if(n[o]<this.__a[t])return;return r(e.event?e.event(n):n)}}}function A(t,n,r,i,a,o,s,c,l,u){var f,m,_,x,S,C,w,T,E,D,O,k,A,ne,ie,oe,M,N,P,F,I=n.type;if(n.constructor!==void 0)return null;if(128&r.__u&&(l=32&r.__u,f=r.__c.__z)){if(n.__u|=l,m=o=[],f.nodeType==8)for(_=1,x=f.nextSibling;x;x=x.nextSibling){if(x.nodeType==8){if(x.data.startsWith(`$s`))_++;else if(x.data.startsWith(`/$s`)&&!--_)break}o.push(x)}else o.push(f);c=o[0]}(f=e.__b)&&f(n);n:if(typeof I==`function`){S=s.length;try{if(D=n.props,O=(f=I.prototype)&&f.render,k=(f=I.contextType)&&i[f.__c],A=f?k?k.props.value:f.__:i,r.__c?2&(C=n.__c=r.__c).__g&&(C.__g|=1):(O?n.__c=C=new I(D,A):(n.__c=C=new y(D,A),C.constructor=I,C.render=j),k&&k.sub(C),C.state||(C.state={}),C.__n=i,C.__g|=8,C.__h=[],C.__k=[]),O&&(C.__s||(C.__s=C.state),I.getDerivedStateFromProps&&(C.__s==C.state&&(C.__s=h({},C.__s)),h(C.__s,I.getDerivedStateFromProps(D,C.__s)))),w=C.props,T=C.state,C.__v=n,r.__c){if(O&&!I.getDerivedStateFromProps&&D!==w&&C.componentWillReceiveProps&&C.componentWillReceiveProps(D,A),n.__v==r.__v&&!(8&C.__g)||!(4&C.__g)&&C.shouldComponentUpdate&&!1===C.shouldComponentUpdate(D,C.__s,A)){n.__v!=r.__v&&(C.props=D,C.state=C.__s,C.__g&=-9),n.__e=r.__e,n.__k=r.__k,n.__k.some(function(e){e&&(e.__=n)}),d.push.apply(C.__h,C.__k),C.__k=[],C.__h.length&&s.push(C),c=b(r);break n}C.componentWillUpdate&&C.componentWillUpdate(D,C.__s,A),O&&C.componentDidUpdate&&C.__h.push(function(){C.componentDidUpdate(w,T,E)})}else O&&!I.getDerivedStateFromProps&&C.componentWillMount&&C.componentWillMount(),O&&C.componentDidMount&&C.__h.push(C.componentDidMount);if(C.context=A,C.props=D,C.__P=t,C.__g&=-5,ne=e.__r,ie=0,O)C.state=C.__s,C.__g&=-9,ne&&ne(n),f=C.render(C.props,C.state,C.context),d.push.apply(C.__h,C.__k),C.__k=[];else do C.__g&=-9,ne&&ne(n),f=C.render(C.props,C.state,C.context),C.state=C.__s;while(8&C.__g&&++ie<25);C.state=C.__s,C.getChildContext&&(i=h({},i,C.getChildContext())),O&&r.__c&&C.getSnapshotBeforeUpdate&&(E=C.getSnapshotBeforeUpdate(w,T)),oe=f&&f.type===v&&f.key==null?f.props.children:f,D.__P&&(f=c,a=(t=D.__P).namespaceURI,l=o=null,r.props&&r.props.__P!=t&&(r.__k.some(function(e){e&&ae(e,e)}),r.__k=null),c=r.__k?b(r,0):null),c=ee(t,p(oe)?oe:[oe],n,r,i,a,o,s,c,l,u),D.__P&&(n.__e=null,c=f),n.__u&=-161,128&r.__u&&(C.__z=null),m&&m.some(g),C.__h.length&&s.push(C),1&C.__g&&(C.__g&=-4)}catch(t){if(s.length=S,n.__v=null,l||o){if(t.then){if(M=0,n.__u|=l?160:128,o){for(~(P=o.indexOf(c||void 0))||(P=o.length);(F=o[P-1])&&F.nodeType==8;)P--;for(;P<o.length;P++)if(F=o[P]){if(o[P]=null,F.nodeType==8){if(F.data.startsWith(`$s`))M++||(N=F);else if(M&&F.data.startsWith(`/$s`)&&!--M){c=F;break}}else if(!M)break}}if(!N){for(;c&&c.nodeType==8&&c.nextSibling;)c=c.nextSibling;N=c}n.__c.__z||(n.__c.__z=N),n.__e=c}else o&&o.some(g)}else n.__e=r.__e;n.__k||=r.__k||[],t.then||te(n),e.__e(t,n,r)}}else c=n.__e=re(r.__e,n,r,i,a,o,s,l,u,t);return(f=e.diffed)&&f(n),128&n.__u?void 0:c}function te(e){e&&(e.__c&&(e.__c.__g|=4),e.__k&&e.__k.some(te))}function ne(t,n,r){for(var i=0;i<r.length;)ie(r[i++],r[i++],r[i++]);e.__c&&e.__c(n,t),t.some(function(n){try{t=n.__h,n.__h=[],t.some(function(e){e.call(n)})}catch(t){e.__e(t,n.__v)}})}function re(t,n,r,i,a,o,s,c,l,d){var h,_,v,y,x,S,C,w,T,E=r.props||u,D=n.props,k=n.type;if(k==`svg`?a=`http://www.w3.org/2000/svg`:k==`math`?a=`http://www.w3.org/1998/Math/MathML`:a||=`http://www.w3.org/1999/xhtml`,o){for(h=0;h<o.length;h++)if((x=o[h])&&(k?x.localName==k:x.nodeType==3)){t=x,o[h]=null;break}}if(!t){if(w=d.ownerDocument||document,!k)return w.createTextNode(D);t=w.createElementNS(a,k,D.is&&D),c&&=(e.__m&&e.__m(n,o),!1),o=null}if(k){if(d=k==`template`?t.content:t,o=k==`textarea`&&D.defaultValue!=null?null:o&&m.call(d.childNodes),!c&&o)for(E={},h=0;h<t.attributes.length;h++)E[(x=t.attributes[h]).name]=x.value;for(h in E)x=E[h],h==`dangerouslySetInnerHTML`?v=x:h==`children`||h in D||h==`value`&&`defaultValue`in D||h==`checked`&&`defaultChecked`in D||O(t,h,null,x,a);for(h in T=1&r.__u,D)x=D[h],h==`children`?y=x:h==`dangerouslySetInnerHTML`?_=x:h==`value`?S=x:h==`checked`?C=x:c&&typeof x!=`function`||!(E[h]!==x||T&&x!=null)||O(t,h,x,E[h],a);_?(c||v&&(_.__html==v.__html||_.__html==t.innerHTML)||(t.innerHTML=_.__html),n.__k=[]):(v&&(t.textContent=``),(k==`foreignObject`||a==`http://www.w3.org/1998/Math/MathML`&&f.test(k))&&(a=`http://www.w3.org/1999/xhtml`),ee(d,p(y)?y:[y],n,r,i,a,o,s,o?o[0]:r.__k&&b(r,0),c,l),o&&o.some(g)),c&&k!=`textarea`||(h=`value`,k==`progress`&&S==null?t.removeAttribute(h):S==null||S===t[h]&&(k!=`progress`||S)||O(t,h,S,E[h],a),h=`checked`,C!=null&&C!=t[h]&&O(t,h,C,E[h],a))}else E===D||c&&t.data==D||(t.data=D);return t}function ie(t,n,r,i){try{typeof t==`function`?n?n.__x=t(n)||1:(i&&=i.__c||i.__e)&&(n=i.__x)&&(i.__x=null,typeof n==`function`?n():t(null)):t.current=n}catch(t){e.__e(t,r)}}function ae(t,n,r){var i,a;if(e.unmount&&e.unmount(t),!(i=t.ref)||i.current&&i.current!=t.__e||ie(i,null,n,t),i=t.__c){if(i.componentWillUnmount)try{i.componentWillUnmount()}catch(t){e.__e(t,n)}i.__P=i.__n=null}if(i=t.__k)for(a=0;a<i.length;a++)i[a]&&ae(i[a],n,typeof t.type!=`function`||r&&!t.props.__P);(i=t.__e)&&(r||g(i),i.__e&&(i.__e=null)),t.__e=t.__c=t.__=null}function j(e,t,n){return this.constructor(e,n)}function oe(t,n){var r,i,a,o;e.__&&e.__(t,n),n.nodeType==9&&(n=n.documentElement),i=(r=t&&32&t.__u)?null:n.__k,n.__k=_(v,{children:[t]}),a=[],o=[],A(n,n.__k,i||u,u,n.namespaceURI,i?null:n.firstChild?m.call(n.childNodes):null,a,i?i.__e:n.firstChild,r,o),ne(a,n.__k,o),n.__k.props.children=null}e={__e:function(e,t,n,r){for(var a,o,s;t=t.__;)if((a=t.__c)&&!(1&a.__g)){a.__g|=4;try{if((o=a.constructor)&&o.getDerivedStateFromError&&(a.setState(o.getDerivedStateFromError(e)),s=8&a.__g),a.componentDidCatch&&(a.componentDidCatch(e,r||{}),s=8&a.__g),s)return void(a.__g|=2)}catch(t){e=t,s=0}}throw i=0,e}},t=0,y.prototype.setState=function(e,t){var n=this.__s;n&&n!=this.state||(n=this.__s=h({},this.state)),typeof e==`function`&&(e=e(h({},n),this.props)),e&&(h(n,e),this.__v&&(t&&this.__k.push(t),S(this)))},y.prototype.forceUpdate=function(e){this.__v&&(this.__g|=4,e&&this.__h.push(e),S(this))},y.prototype.render=v,n=[],i=0,a=function(e,t){return e.__v.__b-t.__v.__b},o=Symbol(),s=/(PointerCapture)$|Capture$/i,c=0,l={};var M=3e3,N=1500,P=()=>({fetch:(...e)=>window.fetch(...e),EventSource:window.EventSource,setTimeout:(e,t)=>window.setTimeout(e,t),clearTimeout:e=>window.clearTimeout(e),visible:()=>document.visibilityState!==`hidden`}),F=e=>{let t=e;return!!t&&t.running===!0&&typeof t.url==`string`&&/^http:\/\/127\.0\.0\.1:\d+$/.test(t.url)&&typeof t.token==`string`};function I(e,t,n=P()){let r=!1,i=null,a=null,o=null,s=null,c=e=>{e!==s&&(s=e,t.status(e))},l=e=>{r||(i=n.setTimeout(()=>void u(),e))};async function u(){if(i=null,r)return;if(!n.visible()){l(M);return}let s=null;try{let t=await n.fetch(e.bridge,{credentials:`same-origin`,cache:`no-store`,headers:{"X-WP-Nonce":e.nonce}});s=t.ok?await t.json():null}catch{s=null}if(r)return;if(!F(s)){c(`off`),l(M);return}if(s.protocol!==e.protocol){c(`outdated`),l(M*4);return}c(`connecting`),o={url:s.url,token:s.token};let u=new n.EventSource(`${s.url}/events?token=${encodeURIComponent(s.token)}`);a=u,u.onmessage=e=>{let n;try{n=JSON.parse(e.data)}catch{return}n.type===`hello`&&c(`connected`),t.event(n)},u.onerror=()=>{u.close(),a===u&&(a=null,o=null),c(`off`),l(N)}}return u(),{async send(e,t){if(!o)return{ok:!1,status:0};try{let r=await n.fetch(`${o.url}${e}`,{method:`POST`,headers:{"content-type":`application/json`,"x-lens-token":o.token},body:JSON.stringify(t)});return{ok:r.ok,status:r.status}}catch{return{ok:!1,status:0}}},close(){r=!0,i!==null&&n.clearTimeout(i),a?.close(),a=null}}}var se=`data-taw-lens-editing`,ce=`taw-lens-editing`,le=()=>/Mac|iPhone|iPad/.test(navigator.platform||navigator.userAgent);function ue(e){let t=e.ownerDocument,n=t.getSelection();if(!n)return;let r=t.createTreeWalker(e,NodeFilter.SHOW_TEXT),i=null;for(let e=r.nextNode();e;e=r.nextNode())e.data.trim()!==``&&(i=e);let a=t.createRange();i?(a.setStart(i,i.data.replace(/\s+$/,``).length),a.collapse(!0)):(a.selectNodeContents(e),a.collapse(!1)),n.removeAllRanges(),n.addRange(a)}function de(e,t){let n=t.replace(/[\r\n]+/g,` `);if(!e.execCommand?.(`insertText`,!1,n)){let t=e.getSelection(),r=t?.rangeCount?t.getRangeAt(0):null;r&&(r.deleteContents(),r.insertNode(e.createTextNode(n)),r.collapse(!1))}}function fe(e,t,n){let r=e.ownerDocument,i=r.defaultView??window,a=e.innerHTML,o=e.getAttribute(`contenteditable`),s=e.hasAttribute(`style`),c=null,l=!1;if(!r.getElementById(ce)){let e=r.createElement(`style`);e.id=ce,e.textContent=`[${se}] { outline: none !important; cursor: text !important; }`,r.head.appendChild(e)}if(e.setAttribute(se,``),t===`text`)e.setAttribute(`contenteditable`,`plaintext-only`),`isContentEditable`in e&&!e.isContentEditable&&e.setAttribute(`contenteditable`,`true`);else{e.setAttribute(`contenteditable`,`true`);try{r.execCommand?.(`defaultParagraphSeparator`,!1,`p`)}catch{}}let u=e=>le()?e.metaKey:e.ctrlKey,d=e=>{if(e.key===`Escape`){e.preventDefault(),e.stopPropagation(),n.cancel();return}if(e.key===`Enter`&&!e.isComposing&&(t===`text`||u(e))){e.preventDefault(),n.save();return}u(e)&&[`b`,`i`,`u`,`k`].includes(e.key.toLowerCase())&&(t===`text`||e.key.toLowerCase()===`u`?e.preventDefault():e.key.toLowerCase()===`k`&&(e.preventDefault(),c=_(),n.link?.()))},f=e=>{t===`text`&&(e.preventDefault(),de(r,e.clipboardData?.getData(`text/plain`)??``))},p=e=>{t===`text`&&e.preventDefault()},m=()=>n.change(),h=t=>{t.target instanceof Node&&e.contains(t.target)&&t.preventDefault()},g=()=>{let t=r.getSelection();t?.anchorNode&&e.contains(t.anchorNode)&&n.select?.()};e.addEventListener(`keydown`,d),e.addEventListener(`paste`,f),e.addEventListener(`drop`,p),e.addEventListener(`input`,m),i.addEventListener(`click`,h,!0),r.addEventListener(`selectionchange`,g);let _=()=>{let t=r.getSelection(),n=t?.rangeCount?t.getRangeAt(0):null;return n&&e.contains(n.commonAncestorContainer)?n.cloneRange():null},v=()=>{if(e.focus(),c){let e=r.getSelection();e?.removeAllRanges(),e?.addRange(c),c=null}},y=()=>{let t=(c??_())?.startContainer??null;for(;t&&t!==e;){if(t instanceof HTMLAnchorElement)return t;t=t.parentNode}return null};return e.focus(),ue(e),{el:e,text:()=>(e.textContent??``).replace(/\s+/g,` `).trim(),html:()=>e.innerHTML,restore(){e.innerHTML=a},stop:()=>{l||(l=!0,e.removeEventListener(`keydown`,d),e.removeEventListener(`paste`,f),e.removeEventListener(`drop`,p),e.removeEventListener(`input`,m),i.removeEventListener(`click`,h,!0),r.removeEventListener(`selectionchange`,g),o===null?e.removeAttribute(`contenteditable`):e.setAttribute(`contenteditable`,o),e.removeAttribute(se),!s&&e.getAttribute(`style`)===``&&e.removeAttribute(`style`),r.querySelector(`[data-taw-lens-editing]`)||r.getElementById(ce)?.remove(),r.activeElement===e&&e.blur())},focus:()=>v(),format(e){v();let t=e===`bold`?`bold`:e===`italic`?`italic`:e===`ul`?`insertUnorderedList`:e===`ol`?`insertOrderedList`:null;t&&(r.execCommand?.(t,!1),n.change())},link(e){v();let t=y();if(e===``){if(t){let e=r.createRange();e.selectNodeContents(t);let n=r.getSelection();n?.removeAllRanges(),n?.addRange(e)}r.execCommand?.(`unlink`,!1)}else if(t)t.setAttribute(`href`,e);else{let t=r.getSelection();t?.isCollapsed&&(t.modify?.(`move`,`backward`,`word`),t.modify?.(`extend`,`forward`,`word`)),r.execCommand?.(`createLink`,!1,e)}n.change()},state(){let e=e=>{try{return r.queryCommandState?.(e)??!1}catch{return!1}};return{bold:e(`bold`),italic:e(`italic`),link:y()!==null,ul:e(`insertUnorderedList`),ol:e(`insertOrderedList`)}},linkText(){let e=y();return e?(e.textContent??``).trim():(c??_())?.toString().trim()??``},linkUrl:()=>y()?.getAttribute(`href`)??``,keepSelection(){c=_()??c}}}var pe=()=>window.wp?.i18n,L=(e,t)=>pe()?.__(e,t)??e;function me(e,t=(e,t)=>fetch(e,t)){return{async save(n){try{let r=await t(e.field,{method:`POST`,credentials:`same-origin`,headers:{"content-type":`application/json`,"x-wp-nonce":e.nonce},body:JSON.stringify(n)}),i=await r.json().catch(()=>({}));return r.ok&&typeof i.saved==`string`?{ok:!0,saved:i.saved,previous:typeof i.previous==`string`?i.previous:null,isDefault:i.default===!0}:{ok:!1,status:r.status,error:typeof i.error==`string`?i.error:`failed`,message:typeof i.message==`string`&&i.message!==``?i.message:r.status===403?L(`WordPress refused the save. Reload the page and try again.`,`taw-core`):L(`Lens couldn’t save that. Try again.`,`taw-core`)}}catch{return{ok:!1,status:0,error:`network`,message:L(`Lens couldn’t reach the site to save. Is it still running?`,`taw-core`)}}},async pages(n){let r=n.trim();if(r===``||/^(https?:|mailto:|tel:|#)/i.test(r))return[];let i=`${e.search}${e.search.includes(`?`)?`&`:`?`}${new URLSearchParams({search:r.replace(/^\//,``),type:`post`,per_page:`8`,_fields:`title,url`})}`;try{let n=await(await t(i,{credentials:`same-origin`,headers:{"x-wp-nonce":e.nonce}})).json();return Array.isArray(n)?n.filter(e=>typeof e.url==`string`).map(t=>({title:t.title??``,url:he(t.url,e.home)})):[]}catch{return[]}}}}function he(e,t){try{let n=new URL(e),r=new URL(t);return n.origin===r.origin?`${n.pathname}${n.search}${n.hash}`:e}catch{return e}}var ge,R,_e,ve,ye=Object.is,be=0,z=[],B=[],V=e,xe=V.__b,Se=V.__r,Ce=V.diffed,H=V.__c,we=V.unmount,Te=V.__;function Ee(e,t){V.__h&&V.__h(R,e,be||t),be=0;var n=R.__H||(R.__H={__:[],__h:[]});return e>=n.__.length&&n.__.push({}),n.__[e]}function U(e){return be=1,De(Le,e)}function De(e,t,n){var r=Ee(ge++,2);if(r.t=e,!r.__c&&(r.__=[n?n(t):Le(void 0,t),function(e){var t=r.__N?r.__N[0]:r.__[0],n=r.t(t,e);ye(t,n)||(r.__N=[n,r.__[1]],r.__c.setState({}))}],r.__c=R,!R.__f)){R.__f=!0;var i=R.shouldComponentUpdate;R.shouldComponentUpdate=function(e,t,n){var r=this.__H;if(!r)return!0;var a=!1,o=this.props!=e;if(r.__.some(function(e){e.__N&&(a=!0,ye(e.__[0],e.__N[0])||(o=!0))}),i){var s=i.call(this,e,t,n);return a?s||o:s}return!a||o}}return r.__}function W(e,t){var n=Ee(ge++,3);!V.__s&&Ie(n.__H,t)&&(n.__P=!0,n.__=e,n.u=t,R.__H.__h.push(n))}function Oe(e,t){var n=Ee(ge++,4);!V.__s&&Ie(n.__H,t)&&(n.__P=!1,n.__=e,n.u=t,R.__h.push(n))}function G(e){return be=5,ke(function(){return{current:e}},[])}function ke(e,t){var n=Ee(ge++,7);return Ie(n.__H,t)&&(n.__=e(),n.__H=t),n.__}function Ae(e,t){return be=8,ke(function(){return e},t)}function je(){var e;do{for(;e=B.shift();)try{K(e)}catch(t){V.__e(t,{__:(e=e.__P)&&e.__v})}for(;e=z.shift();){var t=e.__H;if(e.__P&&t)try{t.__h.some(K),t.__h.some(Fe),t.__h=[]}catch(n){t.__h=[],V.__e(n,e.__v)}}}while(B.length)}V.__b=function(e){R=null,xe&&xe(e)},V.__=function(e,t){e&&t.__k&&t.__k.__m&&(e.__m=t.__k.__m),Te&&Te(e,t)},V.__r=function(e){Se&&Se(e),ge=0;var t=(R=e.__c).__H;t&&(_e==R?R.__h=[]:(t.__h.some(K),t.__h.some(Fe),ge=0),t.__h=[],t.__.some(function(e){e.__N&&(e.__=e.__N),e.u=e.__N=void 0})),_e=R},V.diffed=function(e){Ce&&Ce(e);var t=e.__c;t&&t.__H&&(t.__H.__h.length&&Pe(z.push(t)),t.__H.__.some(function(e){e.u&&(e.__H=e.u)})),_e=R=null},V.__c=function(e,t){t.some(function(e){try{e.__h.some(K),e.__h=e.__h.filter(function(e){return!e.__||Fe(e)})}catch(n){t.some(function(e){e.__h&&=[]}),t=[],V.__e(n,e.__v)}}),H&&H(e,t)},V.unmount=function(e){we&&we(e);var t,n,r=e.__c;r&&r.__H&&(r.__H.__.some(function(r){try{if(r.__P&&r.__c){if(n===void 0){for(n=e.__;n&&(!n.__c||!n.__c.__P);)n=n.__;n&&=n.__c}r.__P=n,Pe(B.push(r))}else K(r)}catch(e){t=e}}),r.__H=void 0,t&&V.__e(t,r.__v))};var Me=typeof requestAnimationFrame==`function`;function Ne(e){var t,n=function(){clearTimeout(r),Me&&cancelAnimationFrame(t),setTimeout(e)},r=setTimeout(n,35);Me&&(t=requestAnimationFrame(n))}function Pe(e){e!=1&&ve==V.requestAnimationFrame||((ve=V.requestAnimationFrame)||Ne)(je)}function K(e){var t=R,n=e.__c;typeof n==`function`&&(e.__c=void 0,n()),R=t}function Fe(e){var t=R;e.__c=e.__(),R=t}function Ie(e,t){return!e||e.length!=t.length||t.some(function(t,n){return!ye(t,e[n])})}function Le(e,t){return typeof t==`function`?t(e):t}function Re(e){if(e.length===0)return``;let t=e.map(e=>e.split(`/`).slice(0,-1)),n=t[0],r=0;for(;r<n.length&&t.every(e=>e[r]===n[r]);)r++;return r>0?`${n.slice(0,r).join(`/`)}/`:``}var ze=e=>e.some(e=>e.state===`running`)?`running`:e.some(e=>e.state===`failed`)?`failed`:`done`,Be=e=>e===`read`||e===`search`||e===`plan`;function Ve(e){let t=[];for(let n of e){let e=t[t.length-1];e&&Be(n.kind)&&e[0].kind===n.kind?e.push(n):t.push([n])}return t}function q(e){let t=ze(e),n=t===`running`,r=t===`failed`,[i]=e;if(r&&e.length===1)switch(i.kind){case`read`:return{text:L(`Couldn’t read %s`,`taw-core`),code:i.target,state:t};case`edit`:return{text:L(`Didn’t edit %s`,`taw-core`),code:i.target,state:t};case`write`:return{text:L(`Didn’t write %s`,`taw-core`),code:i.target,state:t};case`run`:return{text:L(`Didn’t run %s`,`taw-core`),code:i.target,state:t}}switch(i.kind){case`read`:{let r=[...new Set(e.map(e=>e.target).filter(Boolean))];if(r.length<=1)return{text:L(n?`Reading %s`:`Read %s`,`taw-core`),code:r[0]??``,state:t};let i=Re(r),a=String(r.length);return i?{text:L(n?`Reading %d files in %s`:`Read %d files in %s`,`taw-core`).replace(`%d`,a),code:i,state:t}:{text:L(n?`Reading %d files`:`Read %d files`,`taw-core`).replace(`%d`,a),code:``,state:t}}case`search`:return e.length===1?{text:L(n?`Searching for %s`:`Searched for %s`,`taw-core`),code:i.target,state:t}:{text:L(`Searched the theme %d times`,`taw-core`).replace(`%d`,String(e.length)),code:``,state:t};case`edit`:return{text:L(n?`Editing %s`:`Edited %s`,`taw-core`),code:i.target,state:t};case`write`:return{text:L(n?`Writing %s`:`Wrote %s`,`taw-core`),code:i.target,state:t};case`run`:return{text:L(n?`Running %s`:`Ran %s`,`taw-core`),code:i.target,state:t};case`skill`:return{text:L(`Used the %s skill`,`taw-core`),code:i.target,state:t};case`plan`:return{text:L(`Updated its plan`,`taw-core`),code:``,state:t};default:return{text:L(`Used %s`,`taw-core`),code:i.name,state:t}}}var He=e=>Ve(e).map(q),Ue=e=>e.target?`${e.name} ${e.target}`:e.name,We={turns:[],working:!1,busy:null,takenOver:!1,session:null,conversations:[],saves:[],resumed:null},Ge=`connection`,J=(e,t,n)=>{let r=e.turns.findIndex(e=>e.turn===t);if(r<0)return e;let i=e.turns.slice();return i[r]=n(i[r]),{...e,turns:i}},Y=e=>e.items[e.items.length-1],Ke=(e,t,n)=>{for(let r of e.turns)if(r.items.some(e=>e.type===`approval`&&e.id===t))return J(e,r.turn,e=>({...e,items:e.items.map(e=>e.type===`approval`&&e.id===t?{...e,state:n}:e)}));return e};function qe(e,t){switch(t.type){case`hello`:return{...t.history.reduce(qe,{...We,session:t.session,conversations:t.conversations??[]}),working:t.working,busy:t.busy,takenOver:t.takenOver,session:t.session};case`turn`:return{...e,working:!0,turns:[...e.turns,{turn:t.turn,text:t.text,chips:t.chips??[],items:[],...t.from===`edit`?{from:`edit`}:{},...t.at?{at:t.at}:{},...t.page?{page:t.page}:{}}]};case`text`:return J(e,t.turn,e=>{let n=Y(e),r=n?.type===`text`?[...e.items.slice(0,-1),{type:`text`,text:n.text+t.delta}]:[...e.items,{type:`text`,text:t.delta}];return{...e,items:r}});case`tool`:return J(e,t.turn,e=>{let n={id:t.id,name:t.name,kind:t.kind,target:t.target,state:`running`},r=Y(e),i=r?.type===`steps`?[...e.items.slice(0,-1),{type:`steps`,steps:[...r.steps,n]}]:[...e.items,{type:`steps`,steps:[n]}];return{...e,items:i}});case`tool-done`:return J(e,t.turn,e=>({...e,items:e.items.map(e=>e.type===`steps`?{...e,steps:e.steps.map(e=>e.id===t.id?{...e,state:t.ok?`done`:`failed`}:e)}:e)}));case`approval`:return J(e,t.turn,e=>({...e,items:[...e.items,{type:`approval`,id:t.id,tool:t.tool,kind:t.kind,target:t.target,input:t.input??{},state:`pending`}]}));case`approval-done`:return Ke(e,t.id,t.allow?`applied`:`skipped`);case`done`:return J({...e,working:!1},t.turn,e=>({...e,items:e.items.map(e=>e.type===`steps`?{...e,steps:e.steps.map(e=>e.state===`running`?{...e,state:`failed`}:e)}:e.type===`approval`&&e.state!==`applied`?{...e,state:e.state===`pending`||e.state===`sending`?`skipped`:e.state}:e),outcome:t.outcome,message:t.message,detail:t.detail,changed:t.changed??[]}));case`busy`:return{...e,busy:t.busy,takenOver:t.takenOver};case`saved`:return e.saves.some(e=>e.id===t.id)?{...e,saves:e.saves.map(e=>e.id===t.id?{...e,undone:t.undone===!0}:e)}:{...e,saves:[...e.saves,{id:t.id,name:t.name,undone:t.undone===!0,after:e.turns.length}]};case`resumed`:return{...e,resumed:{after:e.turns.length,saves:e.saves.length,at:t.at}};case`conversations`:return{...e,conversations:t.conversations};default:return e}}function Je(e){let t=e.turns.find(e=>e.outcome===void 0);return!e.working||!t?{...e,working:!1}:qe(e,{type:`done`,turn:t.turn,outcome:`error`,message:Ge,changed:[]})}var Ye=(e,t,n)=>Ke(e,t,n);function Xe(e){for(let t of e.turns)for(let e of t.items)if(e.type===`approval`&&(e.state===`pending`||e.state===`sending`))return e;return null}function Ze(e,t=80){let n=e.split(`
`).map(e=>e.trim()).find(e=>e!==``)??``;return n.length>t?`${n.slice(0,t-1)}…`:n}function Qe(e){return e.conversations.find(e=>e.current)?.title||Ze(e.turns[0]?.text??``)||L(`New chat`,`taw-core`)}var $e=(e,t)=>e.getFullYear()===t.getFullYear()&&e.getMonth()===t.getMonth()&&e.getDate()===t.getDate();function et(e,t=new Date,n){let r=e?new Date(e):null;if(!r||Number.isNaN(r.getTime()))return``;let i=new Intl.DateTimeFormat(n,{hour:`numeric`,minute:`2-digit`}).format(r),a=new Date(t);return a.setDate(t.getDate()-1),$e(r,t)?L(`Today, %s`,`taw-core`).replace(`%s`,i):$e(r,a)?L(`Yesterday, %s`,`taw-core`).replace(`%s`,i):`${new Intl.DateTimeFormat(n,{month:`short`,day:`numeric`,...r.getFullYear()===t.getFullYear()?{}:{year:`numeric`}}).format(r)}, ${i}`}function tt(e,t=new Date,n){let r=new Date(e);if(Number.isNaN(r.getTime()))return L(`Resumed`,`taw-core`);let i=new Intl.DateTimeFormat(n,{hour:`numeric`,minute:`2-digit`}).format(r),a=new Date(t);return a.setDate(t.getDate()-1),$e(r,t)?L(`Resumed today, %s`,`taw-core`).replace(`%s`,i):$e(r,a)?L(`Resumed yesterday, %s`,`taw-core`).replace(`%s`,i):L(`Resumed %s`,`taw-core`).replace(`%s`,et(e,t,n))}function nt(e){return e<=0?L(`no changes`,`taw-core`):e===1?L(`1 change`,`taw-core`):L(`%d changes`,`taw-core`).replace(`%d`,String(e))}function rt(e,t,n){let r=et(e.at,n),i=e.current&&t?L(`working…`,`taw-core`):nt(e.changes);return r?`${r} · ${i}`:i}function it(e){return e.working||Xe(e)?L(`Stop Claude first, or wait for it to finish.`,`taw-core`):null}var at=400,ot=e=>e===``?[]:e.replace(/\n$/,``).split(`
`);function st(e,t){let n=ot(e),r=ot(t);if(n.length>at||r.length>at)return[...n.map(e=>({kind:`-`,text:e})),...r.map(e=>({kind:`+`,text:e}))];let i=Array.from({length:n.length+1},()=>Array(r.length+1).fill(0));for(let e=n.length-1;e>=0;e--)for(let t=r.length-1;t>=0;t--)i[e][t]=n[e]===r[t]?i[e+1][t+1]+1:Math.max(i[e+1][t],i[e][t+1]);let a=[],o=0,s=0;for(;o<n.length&&s<r.length;)n[o]===r[s]?(a.push({kind:` `,text:n[o]}),o++,s++):i[o+1][s]>=i[o][s+1]?a.push({kind:`-`,text:n[o++]}):a.push({kind:`+`,text:r[s++]});for(;o<n.length;)a.push({kind:`-`,text:n[o++]});for(;s<r.length;)a.push({kind:`+`,text:r[s++]});return a}function ct(e){return e.edits?e.edits.flatMap((e,t)=>[...t>0?[{kind:` `,text:`…`}]:[],...st(e.old,e.new)]):typeof e.content==`string`?ot(e.content).map(e=>({kind:`+`,text:e})):[]}var lt=0;Array.isArray;function X(t,n,r,i,a,o){n||={};var s,c,l=n;if(`ref`in l&&typeof t!=`function`)for(c in l={},n)c==`ref`?s=n[c]:l[c]=n[c];var u={type:t,props:l,key:r,ref:s,__k:null,__:null,__b:0,__e:null,__c:null,constructor:void 0,__v:--lt,__i:-1,__u:0};return(a||o)&&(u.__source=a,u.__self=o),e.vnode&&e.vnode(u),u}var Z=({size:e=16,width:t=1.8,children:n})=>X(`svg`,{width:e,height:e,viewBox:`0 0 24 24`,fill:`none`,stroke:`currentColor`,"stroke-width":t,"stroke-linecap":`round`,"stroke-linejoin":`round`,"aria-hidden":`true`,focusable:`false`,children:n}),ut=e=>X(Z,{...e,children:[X(`circle`,{cx:`11`,cy:`11`,r:`6.5`}),X(`path`,{d:`M16 16l4.5 4.5`})]}),dt=e=>X(Z,{...e,children:X(`path`,{d:`M5 3l14 7-6 2-2 6z`})}),ft=e=>X(Z,{...e,children:X(`path`,{d:`M4 5h6M8 12h6M8 19h6M6 5v14M6 12h2M6 19h2`})}),pt=e=>X(Z,{...e,children:[X(`rect`,{x:`4`,y:`6`,width:`12`,height:`12`,rx:`2`}),X(`path`,{d:`M13 3h8v8M21 3l-8 8`})]}),mt=e=>X(Z,{...e,children:[X(`rect`,{x:`3`,y:`4`,width:`18`,height:`16`,rx:`2`}),X(`path`,{d:`M15 4v16`})]}),ht=({side:e,...t})=>X(Z,{...t,children:X(`path`,{d:e===`right`?`M9 6l6 6-6 6`:`M15 6l-6 6 6 6`})}),gt=e=>X(Z,{...e,children:X(`path`,{d:`M6 12h12`})}),_t=e=>X(Z,{...e,width:2,children:X(`path`,{d:`M12 19V5M6 11l6-6 6 6`})}),vt=e=>X(Z,{...e,children:[X(`circle`,{cx:`12`,cy:`12`,r:`8`}),X(`path`,{d:`M12 4a8 8 0 0 1 0 16z`,fill:`currentColor`})]}),yt=e=>X(Z,{...e,children:[X(`circle`,{cx:`12`,cy:`12`,r:`4`}),X(`path`,{d:`M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4`})]}),bt=e=>X(Z,{...e,children:X(`path`,{d:`M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z`})}),xt=({size:e=14})=>X(`svg`,{width:e,height:e,viewBox:`0 0 24 24`,fill:`currentColor`,"aria-hidden":`true`,focusable:`false`,children:[X(`circle`,{cx:`9`,cy:`6`,r:`1.6`}),X(`circle`,{cx:`15`,cy:`6`,r:`1.6`}),X(`circle`,{cx:`9`,cy:`12`,r:`1.6`}),X(`circle`,{cx:`15`,cy:`12`,r:`1.6`}),X(`circle`,{cx:`9`,cy:`18`,r:`1.6`}),X(`circle`,{cx:`15`,cy:`18`,r:`1.6`})]}),St=({size:e=14})=>X(Z,{size:e,width:2,children:X(`path`,{d:`M6 6l12 12M18 6L6 18`})}),Ct=({size:e=12})=>X(Z,{size:e,width:2,children:X(`path`,{d:`M6 9l6 6 6-6`})}),wt=({size:e=12})=>X(Z,{size:e,width:2,children:X(`path`,{d:`M9 6l6 6-6 6`})}),Tt=({size:e=14})=>X(Z,{size:e,children:[X(`rect`,{x:`5`,y:`3`,width:`14`,height:`18`,rx:`2`}),X(`path`,{d:`M9 8h6M9 12h6`})]}),Et=({size:e=14})=>X(Z,{size:e,children:[X(`rect`,{x:`4`,y:`4`,width:`16`,height:`16`,rx:`2`}),X(`path`,{d:`M4 9h16`})]}),Dt=({size:e=14})=>X(Z,{size:e,children:[X(`rect`,{x:`4`,y:`4`,width:`7`,height:`7`,rx:`1`}),X(`rect`,{x:`13`,y:`4`,width:`7`,height:`7`,rx:`1`}),X(`rect`,{x:`4`,y:`13`,width:`16`,height:`7`,rx:`1`})]}),Ot=({size:e=14})=>X(Z,{size:e,children:X(`path`,{d:`M5 7h14M5 12h10M5 17h7`})}),kt=({size:e=14})=>X(Z,{size:e,children:X(`path`,{d:`M6 6h12M12 6v12`})}),At=({size:e=14})=>X(Z,{size:e,width:2,children:X(`path`,{d:`M5 12.5l4.5 4.5L19 7.5`})}),jt=({size:e=14})=>X(Z,{size:e,width:2,children:X(`path`,{d:`M7 7l10 10M17 7L7 17`})}),Mt=({size:e=14})=>X(`svg`,{class:`spinner`,width:e,height:e,viewBox:`0 0 24 24`,fill:`none`,"aria-hidden":`true`,focusable:`false`,children:X(`path`,{d:`M12 3.5a8.5 8.5 0 1 1-8.5 8.5`,stroke:`currentColor`,"stroke-width":`2`,"stroke-linecap":`round`})}),Nt=({size:e=16})=>X(Z,{size:e,children:[X(`circle`,{cx:`12`,cy:`12`,r:`8.5`}),X(`path`,{d:`M12 7.5v5.5M12 16.4v.1`})]}),Pt=({size:e=22})=>X(Z,{size:e,children:[X(`path`,{d:`M9.5 8H8a4 4 0 0 0 0 8h1.5M14.5 8H16a4 4 0 0 1 0 8h-1.5`}),X(`path`,{d:`M10 12h.01M14 12h.01`})]}),Ft=({size:e=10})=>X(`svg`,{width:e,height:e,viewBox:`0 0 10 10`,fill:`currentColor`,"aria-hidden":`true`,focusable:`false`,children:X(`rect`,{width:`10`,height:`10`,rx:`1.5`})}),It=e=>X(Z,{...e,width:2,children:X(`path`,{d:`M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1`})}),Lt=e=>X(Z,{...e,width:2,children:[X(`path`,{d:`M9 6h11M9 12h11M9 18h11`}),X(`circle`,{cx:`4.5`,cy:`6`,r:`1`}),X(`circle`,{cx:`4.5`,cy:`12`,r:`1`}),X(`circle`,{cx:`4.5`,cy:`18`,r:`1`})]}),Rt=e=>X(Z,{...e,width:2,children:X(`path`,{d:`M10 6h10M10 12h10M10 18h10M4 5h1v4M4 15h2l-2 3h2`})}),zt=e=>X(Z,{...e,children:[X(`path`,{d:`M12 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-6`}),X(`path`,{d:`M17.5 3.5a2.1 2.1 0 0 1 3 3L13 14l-4 1 1-4z`})]});function Bt(e){let t=[],n=e.replace(/\r\n?/g,`
`).split(`
`),r=[],i=()=>{r.length&&(t.push({kind:`p`,text:r.join(`
`)}),r=[])};for(let e=0;e<n.length;e++){let a=n[e];if(/^\s*```/.test(a)){i();let r=[];for(;++e<n.length&&!/^\s*```/.test(n[e]);)r.push(n[e]);t.push({kind:`code`,text:r.join(`
`)});continue}let o=/^\s*[-*+]\s+(.*)$/.exec(a),s=/^\s*\d+[.)]\s+(.*)$/.exec(a);if(o||s){i();let e=o?`ul`:`ol`,n=t[t.length-1],r=(o??s)[1];n&&n.kind===e?n.items.push(r):t.push({kind:e,items:[r]});continue}let c=/^#{1,6}\s+(.*)$/.exec(a);if(c){i(),t.push({kind:`h`,text:c[1]});continue}if(a.trim()===``){i();continue}let l=t[t.length-1];if(r.length===0&&/^\s{2,}\S/.test(a)&&l&&(l.kind===`ul`||l.kind===`ol`)){l.items[l.items.length-1]+=` ${a.trim()}`;continue}r.push(a)}return i(),t}var Vt=/^https?:\/\//i;function Ht(e){let t=[],n=/(`+)([^`]+?)\1|\*\*([^*]+)\*\*|\*([^*\s][^*]*?)\*|(?<![\w])_([^_\s][^_]*?)_(?![\w])|\[([^\]]+)\]\(([^)\s]+)\)/g,r=0,i,a=0;for(;i=n.exec(e);){if(i.index>r&&t.push(e.slice(r,i.index)),i[2]!==void 0)t.push(X(`code`,{children:i[2]},a++));else if(i[3]!==void 0)t.push(X(`strong`,{children:Ht(i[3])},a++));else if(i[4]!==void 0||i[5]!==void 0)t.push(X(`em`,{children:Ht(i[4]??i[5])},a++));else{let e=i[7];t.push(Vt.test(e)?X(`a`,{href:e,target:`_blank`,rel:`noopener noreferrer`,children:i[6]},a++):i[6])}r=i.index+i[0].length}return r<e.length&&t.push(e.slice(r)),t}var Ut=e=>e.split(`
`).flatMap((e,t)=>t===0?Ht(e):[X(`br`,{},`br${t}`),...Ht(e)]);function Wt({source:e}){return X(`div`,{class:`md`,children:Bt(e).map((e,t)=>{switch(e.kind){case`code`:return X(`pre`,{children:X(`code`,{children:e.text})},t);case`h`:return X(`p`,{children:X(`strong`,{children:Ht(e.text)})},t);case`ul`:return X(`ul`,{children:e.items.map((e,t)=>X(`li`,{children:Ht(e)},t))},t);case`ol`:return X(`ol`,{children:e.items.map((e,t)=>X(`li`,{children:Ht(e)},t))},t);default:return X(`p`,{children:Ut(e.text)},t)}})})}var Gt=`taw lens`;function Kt({text:e,code:t}){let[n,r=``]=e.split(`%s`);return X(`span`,{class:`step-text`,children:[n,t&&X(`code`,{children:t}),r]})}var qt=({state:e})=>e===`running`?X(`span`,{class:`step-icon`,children:[X(Mt,{size:13}),X(`span`,{class:`sr-only`,children:L(`In progress`,`taw-core`)})]}):e===`failed`?X(`span`,{class:`step-icon`,children:[X(jt,{size:13}),X(`span`,{class:`sr-only`,children:L(`Didn’t finish`,`taw-core`)})]}):X(`span`,{class:`step-icon`,children:[X(At,{size:13}),X(`span`,{class:`sr-only`,children:L(`Done`,`taw-core`)})]});function Jt({steps:e}){let[t,n]=U(!1);return X(`div`,{class:`activity`,children:[He(e).map((e,r)=>X(`div`,{class:`step`,children:[X(qt,{state:e.state}),X(Kt,{text:e.text,code:e.code}),r===0&&X(`button`,{type:`button`,class:`text-button step-details`,"aria-expanded":t,onClick:()=>n(!t),children:L(t?`Hide details`:`Details`,`taw-core`)})]},r)),t&&X(`ul`,{class:`step-list`,children:e.map(e=>X(`li`,{children:[X(qt,{state:e.state}),X(`code`,{children:Ue(e)})]},e.id))})]})}function Yt(e){switch(e.kind){case`edit`:return L(`Edit 1 file`,`taw-core`);case`write`:return L(`Write 1 file`,`taw-core`);case`run`:return L(`Run a command`,`taw-core`);default:return L(`Use %s`,`taw-core`).replace(`%s`,e.tool)}}function Xt({item:e,onAnswer:t}){let n=Yt(e),r=`approval-${e.id}`,i=e.input.file??``;if(e.state===`applied`||e.state===`skipped`)return X(`div`,{class:`approval-done`,children:[X(`span`,{class:`step-icon`,children:e.state===`applied`?X(At,{size:13}):X(jt,{size:13})}),X(Kt,{text:e.state===`applied`?L(`Applied: %s`,`taw-core`):L(`Skipped: %s`,`taw-core`),code:i||e.target||e.tool})]});let a=ct(e.input),o=a.slice(0,200),s=e.kind===`edit`||e.kind===`write`;return X(`div`,{class:`approval`,role:`group`,"aria-labelledby":r,children:[X(`div`,{class:`approval-head`,children:[X(`span`,{class:`approval-dot`,"aria-hidden":`true`}),X(`span`,{class:`approval-title`,id:r,children:n}),i&&X(`span`,{class:`approval-file`,children:i})]}),a.length>0?X(`pre`,{class:`diff`,"aria-label":L(`Proposed change`,`taw-core`),children:[o.map((e,t)=>X(`div`,{class:`diff-line`,"data-kind":e.kind===`-`?`removed`:e.kind===`+`?`added`:void 0,children:[X(`span`,{class:`diff-sign`,"aria-hidden":`true`,children:e.kind===` `?` `:e.kind}),X(`span`,{class:`sr-only`,children:e.kind===`-`?L(`Removed:`,`taw-core`):e.kind===`+`?L(`Added:`,`taw-core`):``}),e.text]},t)),a.length>o.length&&X(`div`,{class:`diff-line diff-more`,children:L(`… %d more lines`,`taw-core`).replace(`%d`,String(a.length-o.length))})]}):e.input.command===void 0?e.input.json===void 0?null:X(`div`,{class:`approval-body`,children:X(`pre`,{class:`approval-code`,children:e.input.json})}):X(`div`,{class:`approval-body`,children:[e.input.description&&X(`div`,{class:`approval-note`,children:e.input.description}),X(`pre`,{class:`approval-code`,children:e.input.command})]}),X(`div`,{class:`approval-actions`,children:[X(`button`,{type:`button`,class:`card-button primary`,disabled:e.state===`sending`,onClick:()=>t(e.id,!0),children:L(s?`Apply`:`Allow`,`taw-core`)}),X(`button`,{type:`button`,class:`card-button`,disabled:e.state===`sending`,onClick:()=>t(e.id,!1),children:L(`Skip`,`taw-core`)})]})]})}function Zt({turn:e,onRetry:t,retry:n}){let[r,i]=U(!1),a=e.changed??[],o=e.message===`connection`||e.message===`agent-exit`?L(`The connection to Claude dropped.`,`taw-core`):L(`Claude reported an error.`,`taw-core`),s=[e.message!==`connection`&&e.message!==`agent-exit`?e.message:``,e.detail??``].filter(Boolean).join(`

`);return X(`div`,{class:`notice`,role:`alert`,children:[X(`div`,{class:`notice-head`,children:[X(Nt,{size:15}),X(`span`,{children:L(`Claude stopped before finishing`,`taw-core`)})]}),X(`p`,{class:`notice-text`,children:[o,` `,a.length?L(`It had changed %s by then.`,`taw-core`).replace(`%s`,a.join(`, `)):L(`Nothing was changed after the last step you saw.`,`taw-core`)]}),X(`div`,{class:`notice-actions`,children:[X(`button`,{type:`button`,class:`card-button primary`,disabled:!n,onClick:()=>t(e),children:L(`Try again`,`taw-core`)}),s&&X(`button`,{type:`button`,class:`card-button`,"aria-expanded":r,onClick:()=>i(!r),children:L(`Details`,`taw-core`)})]}),r&&s&&X(`pre`,{class:`notice-detail`,children:s})]})}var Qt=e=>{if(!e)return``;let t=new Date(e);return Number.isNaN(t.getTime())?``:t.toLocaleTimeString([],{hour:`2-digit`,minute:`2-digit`})};function $t({busy:e,takenOver:t,onTakeOver:n}){let[r,i]=U(`open`);if(t)return X(`div`,{class:`busy-line`,children:L(`You took over this theme. The other Claude session is still running: you may overwrite each other’s changes.`,`taw-core`)});let a=Qt(e.since);return r===`waiting`?X(`div`,{class:`busy busy-compact`,role:`status`,children:[X(`span`,{class:`approval-dot`,"aria-hidden":`true`}),X(`span`,{children:L(`Waiting for the other Claude session. Lens writes no files until it’s free.`,`taw-core`)}),X(`button`,{type:`button`,class:`text-button`,onClick:()=>i(`confirm`),children:L(`Take over`,`taw-core`)})]}):X(`div`,{class:`busy`,role:`status`,children:[X(`div`,{class:`busy-head`,children:[X(`span`,{class:`approval-dot`,"aria-hidden":`true`}),X(`span`,{children:L(r===`confirm`?`Take over the theme?`:`Another Claude session is editing this theme`,`taw-core`)})]}),X(`p`,{class:`busy-text`,children:r===`confirm`?L(`Lens will write files again while the other session keeps running. If you both change the same file, one of you overwrites the other.`,`taw-core`):`${a?L(`A Claude session started at %s holds the theme.`,`taw-core`).replace(`%s`,a):L(`A Claude session holds the theme.`,`taw-core`)} ${L(`You can keep picking and asking; Lens won’t write files until it’s free.`,`taw-core`)}`}),X(`div`,{class:`busy-actions`,children:r===`confirm`?X(v,{children:[X(`button`,{type:`button`,class:`card-button primary`,onClick:n,children:L(`Take over`,`taw-core`)}),X(`button`,{type:`button`,class:`card-button`,onClick:()=>i(`open`),children:L(`Cancel`,`taw-core`)})]}):X(v,{children:[X(`button`,{type:`button`,class:`card-button primary`,onClick:()=>i(`waiting`),children:L(`Wait for it`,`taw-core`)}),X(`button`,{type:`button`,class:`card-button`,onClick:()=>i(`confirm`),children:L(`Take over`,`taw-core`)})]})})]})}function en({outdated:e}){let[t,n]=U(!1);return X(`div`,{class:`empty`,children:[X(Pt,{}),X(`div`,{class:`empty-title`,children:L(e?`Lens needs a restart`:`Lens isn’t connected`,`taw-core`)}),X(`div`,{class:`empty-text`,children:L(e?`The running taw lens is from another taw/core version. Stop it (Ctrl-C) and start it again in this site’s theme folder:`:`Start it in this site’s theme folder:`,`taw-core`)}),X(`div`,{class:`command`,children:[X(`code`,{children:Gt}),X(`button`,{type:`button`,class:`command-copy`,onClick:()=>{navigator.clipboard?.writeText(Gt).then(()=>{n(!0),setTimeout(()=>n(!1),1500)},()=>{})},children:L(t?`Copied`:`Copy`,`taw-core`)})]}),X(`div`,{class:`empty-note`,children:L(`Lens reconnects on its own once it’s running.`,`taw-core`)}),X(`div`,{class:`empty-note`,children:L(`Picking and the inspect card still work.`,`taw-core`)})]})}var tn=()=>X(`div`,{class:`skeleton`,"aria-hidden":`true`,children:[X(`span`,{style:{width:`58%`}}),X(`span`,{style:{width:`82%`}}),X(`span`,{style:{width:`38%`}})]});function nn({turn:e,last:t,working:n,here:r=``,onAnswer:i,onRetry:a,retry:o}){let s=e.items[e.items.length-1],c=t&&n&&e.outcome===void 0&&(!s||s.type===`steps`&&s.steps.every(e=>e.state!==`running`));return X(`div`,{class:`turn`,children:[X(`div`,{class:`message`,children:[e.chips.length>0&&X(`div`,{class:`message-chips`,children:e.chips.map((t,n)=>e.page&&r&&e.page!==r?X(`span`,{class:`chip chip-away`,title:L(`Not on this page`,`taw-core`),children:t},n):X(`span`,{class:`chip`,children:t},n))}),X(`div`,{class:`bubble`,children:e.text}),e.from===`edit`&&X(`span`,{class:`message-from`,children:L(`Sent from an edit on the page`,`taw-core`)})]}),e.items.map((e,t)=>e.type===`text`?X(`div`,{class:`reply`,children:X(Wt,{source:e.text})},t):e.type===`steps`?X(Jt,{steps:e.steps},t):X(Xt,{item:e,onAnswer:i},e.id)),c&&X(`div`,{class:`activity`,children:X(`div`,{class:`step`,children:[X(qt,{state:`running`}),X(`span`,{class:`step-text`,children:L(`Thinking…`,`taw-core`)})]})}),e.outcome===`stopped`&&X(`div`,{class:`turn-note`,children:L(`Stopped.`,`taw-core`)}),e.outcome===`error`&&X(Zt,{turn:e,onRetry:a,retry:o})]})}function rn({note:e,onUndo:t,past:n}){return X(`div`,{class:`saved`,children:[X(`div`,{class:`saved-line`,children:[X(At,{}),X(`span`,{class:`saved-text`,children:[e.state===`undone`?L(`Undid the change to`,`taw-core`):L(`Saved to`,`taw-core`),` `,X(`code`,{class:`mono`,children:e.name})]}),e.state===`saved`&&!n&&t&&X(`button`,{type:`button`,class:`saved-undo`,onClick:()=>t(e.id),children:L(`Undo`,`taw-core`)}),e.state===`undoing`&&X(`span`,{class:`saved-undo`,"aria-label":L(`Undoing…`,`taw-core`),children:X(Mt,{size:12})})]}),e.error&&X(`div`,{class:`saved-error`,role:`alert`,children:e.error})]})}function an({chat:e,connection:t,notes:n=[],here:r=``,onAnswer:i,onRetry:a,onTakeOver:o,onUndo:s=()=>void 0}){let c=t===`connected`,l=e.turns.length,u=(e,t)=>t<l?e===t:e>=t,d=e.resumed,f=new Set(n.map(e=>e.key).filter(Boolean)),p=e.saves.map((e,t)=>({save:e,n:t})).filter(({save:e})=>!f.has(e.id)),m=(e,t)=>p.filter(({save:n,n:r})=>u(n.after,e)&&(t===`all`||!d||(t===`before`?r<d.saves:r>=d.saves))).map(({save:e})=>X(rn,{past:!0,note:{id:0,after:e.after,name:e.name,state:e.undone?`undone`:`saved`}},`save-${e.id}`)),h=e=>n.filter(t=>u(t.after,e)).map(e=>X(rn,{note:e,onUndo:s},`note-${e.id}`)),g=e=>d&&(e<l?d.after===e:d.after>=e)?X(v,{children:[m(e,`before`),X(`div`,{class:`divider`,role:`separator`,children:tt(d.at)}),h(e),m(e,`after`)]}):X(v,{children:[m(e,`all`),h(e)]}),_=e.turns[0];return X(`div`,{class:`conversation`,role:`log`,"aria-label":L(`Conversation`,`taw-core`),children:[e.busy&&c&&X($t,{busy:e.busy,takenOver:e.takenOver,onTakeOver:o}),d&&d.after>0&&_&&X(`div`,{class:`divider`,role:`separator`,children:_.at?L(`Earlier · %s`,`taw-core`).replace(`%s`,et(_.at)):L(`Earlier`,`taw-core`)}),e.turns.map((t,n)=>X(v,{children:[g(n),X(nn,{turn:t,last:n===l-1,working:e.working,here:r,onAnswer:i,onRetry:a,retry:c&&!e.working&&n===l-1&&(!d||n>=d.after)})]},t.turn)),g(l)]})}function on({chat:e,open:t,onToggle:n,buttonRef:r}){let i=Qe(e);return X(`button`,{ref:r,type:`button`,class:`conv-title`,"aria-haspopup":`menu`,"aria-expanded":t,"aria-label":L(`Conversation: %s. New chat and recent conversations`,`taw-core`).replace(`%s`,i),title:i,onClick:n,onKeyDown:e=>{e.key===`ArrowDown`&&!t&&(e.preventDefault(),n())},children:[X(`span`,{class:`conv-title-text`,children:i}),X(Ct,{})]})}function sn({chat:e,host:t,onNew:n,onOpen:r,onClose:i}){let a=G(null),o=it(e),s=()=>[...a.current?.querySelectorAll(`[role^="menuitem"]`)??[]],c=()=>s().filter(e=>e.getAttribute(`aria-disabled`)!==`true`);return W(()=>{(c()[0]??s()[0])?.focus()},[]),X(`div`,{class:`conv-menu`,role:`menu`,"aria-label":L(`Conversations`,`taw-core`),ref:a,onKeyDown:e=>{if(e.key===`Escape`){e.preventDefault(),e.stopPropagation(),i(!0);return}if(e.key===`Tab`){i(!1);return}if(e.key===`ArrowDown`||e.key===`ArrowUp`||e.key===`Home`||e.key===`End`){e.preventDefault();let t=c();if(t.length===0)return;let n=t.indexOf(e.target);t[e.key===`Home`?0:e.key===`End`?t.length-1:(n+(e.key===`ArrowDown`?1:-1)+t.length)%t.length]?.focus()}},children:[X(`button`,{type:`button`,role:`menuitem`,class:`conv-new`,"aria-disabled":o?`true`:void 0,onClick:()=>!o&&n(),children:[X(zt,{}),L(`New chat`,`taw-core`)]}),o&&X(`div`,{class:`conv-reason`,role:`note`,children:o}),X(`div`,{class:`conv-rule`,role:`separator`}),X(`div`,{class:`conv-label`,"aria-hidden":`true`,children:L(`In this theme`,`taw-core`)}),e.conversations.length===0?X(`div`,{class:`conv-empty`,children:L(`No earlier conversations in this theme yet. They appear here once you send a message.`,`taw-core`)}):e.conversations.map(t=>{let n=!t.current&&o!==null;return X(`button`,{type:`button`,role:`menuitemradio`,class:`conv-item`,"aria-checked":t.current,"aria-disabled":n?`true`:void 0,onClick:()=>{t.current?i(!0):n||r(t.id)},children:[X(`span`,{class:`conv-tick`,"aria-hidden":`true`,children:t.current&&X(At,{})}),X(`span`,{class:`conv-text`,children:[X(`span`,{class:`conv-name`,children:t.title}),X(`span`,{class:`conv-detail`,children:rt(t,e.working)})]})]},t.id||`current`)}),t&&X(`div`,{class:`conv-host`,children:t})]})}var cn=`taw-lens-map`,ln=e=>typeof e==`object`&&!!e&&!Array.isArray(e);function un(e){if(!e)return null;let t;try{t=JSON.parse(e)}catch{return null}if(!ln(t)||t.version!==1)return null;let n=Array.isArray(t.nodes)?t.nodes:ln(t.nodes)?Object.values(t.nodes):[],r=new Map;for(let e of n)ln(e)&&typeof e.id==`number`&&r.set(e.id,{...e,reads:Array.isArray(e.reads)?e.reads:[]});return r.has(0)?{version:1,nodes:r,files:ln(t.files)?t.files:{}}:null}var dn=e=>un(e.getElementById(cn)?.textContent);function fn(e,t){let n=[],r=new Set;for(let i=e.nodes.get(t);i&&!r.has(i.id);)n.push(i),r.add(i.id),i=i.parent===null?void 0:e.nodes.get(i.parent);return n}var Q=(e,t)=>e.length>t?`${e.slice(0,t-1).trimEnd()}…`:e,pn=e=>e.store===`wp`||e.store===`menu`||e.type===`wp`;function mn(e){switch(e){case`wysiwyg`:return L(`rich text`,`taw-core`);case`textarea`:return L(`long text`,`taw-core`);case`url`:case`link`:return L(`link`,`taw-core`);case`image`:return L(`image`,`taw-core`);case`repeater`:return L(`repeater`,`taw-core`);case`number`:return L(`number`,`taw-core`);case`select`:case`radio`:return L(`choice`,`taw-core`);default:return L(`text`,`taw-core`)}}function hn(e,t=e.key){return e.store===`menu`?`${L(`Menu item`,`taw-core`)} “${Q(String(e.value??``),32)}”`:pn(e)?e.key===`post_title`?L(`Post title`,`taw-core`):e.label:t}var gn=e=>e.kind===`field`?hn(e.read,e.path):`“${Q(e.text,40)}”`,_n=e=>e.label||e.name;function vn(e,t,n){let r=fn(e,t).reverse().map(_n).filter(e=>e!==``);return n&&r.push(n),r.join(` › `)}function yn(e,t){let n=fn(e,t).find(e=>e.kind!==`page`)??e.nodes.get(0);return n?n.name:``}function bn(e,t){let n=t.attribution,r=yn(e,n.kind===`block`?n.node:t.at),i=n.kind===`field`||n.kind===`template`?gn(n.source):n.kind===`ambiguous`?L(`ambiguous`,`taw-core`):n.kind===`mixed`?L(`mixed`,`taw-core`):n.kind===`element`?t.elements[0]?.tagName.toLowerCase()??``:``;return i?`${r} › ${i}`:r}function xn(e,t){let n=t.attribution;if(n.kind===`field`||n.kind===`template`)return gn(n.source);if(n.kind===`block`){let t=e.nodes.get(n.node);return t?_n(t):``}return n.kind===`element`?`<${t.elements[0]?.tagName.toLowerCase()??`?`}>`:`“${Q(n.text,24)}”`}function Sn(e,t,n=1){return e.replace(`{path}`,encodeURI(t)).replace(`{line}`,String(n))}var Cn=400,wn=(e,t=Cn)=>JSON.stringify(Q(e.replace(/\s+/g,` `).trim(),t));function Tn(e){let t=[];for(let n=e;n&&t.length<5;n=n.parentElement){let e=n.tagName.toLowerCase();if(e===`html`||e===`body`)break;if(n.id&&/^[A-Za-z][\w-]*$/.test(n.id)){t.unshift(`#${n.id}`);break}let r=e+[...n.classList].filter(e=>/^[A-Za-z_-][\w-]*$/.test(e)).slice(0,2).map(e=>`.${e}`).join(``),i=n.parentElement;if(i){let e=[...i.children].filter(e=>e.tagName===n.tagName);e.length>1&&(r+=`:nth-of-type(${e.indexOf(n)+1})`)}t.unshift(r)}return t.join(` > `)}function En(e){switch(e.store){case`post`:return`post ${e.object}`;case`term`:return`term ${e.object}`;case`user`:return`user ${e.object}`;case`option`:return`options page ${e.object} (taw fields:set options …)`;case`menu`:return`menu ${e.object}, ${e.key}`;default:return`WordPress settings`}}function Dn(e,t){let n=fn(e,t),r=n[0],i=n.slice().reverse().map(_n).filter(Boolean).join(` › `);return r?`${i} (${r.kind===`block`?`block ${r.name}`:r.kind===`part`?`template part`:`page template`}${r.template?`, ${r.template}`:``})`:i}function On(e,t){let{read:n}=t,r=[];if(pn(n))r.push(`WordPress data: ${n.label}${n.key===`post_title`?` of post ${n.object}`:``}`),n.store===`menu`&&r.push(`Menu item ${n.key} in menu ${n.object}${n.target?`, linking to post ${n.target}`:``}`);else{let e=/^(.+?)\[(\d+)\]\.(.+)$/.exec(t.path);r.push(e?`Field ${e[1]}, row ${Number(e[2])+1}, sub-field ${e[3]} (${t.label}; repeater)`:`Field ${t.path} (${t.label}, ${n.type})`),r.push(`Stored on: ${En(n)}`)}let i=typeof n.value==`string`?n.value:JSON.stringify(n.value??``);return r.push(`Value: ${wn(i)}${t.part?` (the element shows part of it)`:``}`),n.default&&r.push(`Nothing is stored yet: the page shows the field’s default.`),r.push(`Rendered in: ${Dn(e,t.node)}`),n.admin&&r.push(`By hand: ${n.admin}`),r}function kn(e,t){let n=t.how===`i18n`?`, translatable (inside a gettext call such as esc_html_e)`:t.how===`literal`?`, a PHP string`:``;return[`Template text ${wn(t.text,200)}`,`File: ${t.file} line ${t.line}${n}`,`Rendered in: ${Dn(e,t.node)}`]}var An=(e,t)=>t.kind===`field`?On(e,t):kn(e,t);function jn(e,t){let n=t.attribution;switch(n.kind){case`field`:case`template`:return An(e,n.source);case`ambiguous`:return[`Ambiguous: the text ${wn(n.text,200)} comes from ${n.sources.length} places. Don't pick one: ask which.`,...n.sources.flatMap((t,n)=>[`Candidate ${n+1}:`,...An(e,t).map(e=>`  ${e}`)])];case`mixed`:return[`Mixed: template text around values. The text: ${wn(n.text,200)}`,...n.sources.flatMap((t,n)=>[`Piece ${n+1}:`,...An(e,t).map(e=>`  ${e}`)])];case`block`:{let t=e.nodes.get(n.node);if(!t)return[`A block`];let r=[t.kind===`block`?`Block ${t.name} (${_n(t)})`:t.kind===`part`?`Template part ${t.name}`:`The page (${t.template??t.name})`,`Rendered in: ${Dn(e,n.node)}`];t.files?.length?r.push(`Files: ${t.files.join(`, `)}`):t.template&&r.push(`File: ${t.template}`);let i=t.reads.filter(e=>!pn(e)).map(e=>`${e.key} (${e.label})`);return i.length&&r.push(`Its fields: ${i.join(`, `)}`),r}default:return[`An element whose text TAW didn't render from a field or template text${n.text?`: ${wn(n.text,200)}`:``}. JavaScript may add it, or it is computed.`,`Rendered in: ${Dn(e,n.node)}`]}}function Mn(e,t,n){if(!e||t.length===0)return``;let r=t.map((t,n)=>{let r=t.elements[0],i=jn(e,t);return r&&i.push(`Element: <${r.tagName.toLowerCase()}> at \`${Tn(r)}\``),[`${n+1}. ${i[0]}`,...i.slice(1).map(e=>`   ${e}`)].join(`
`)});return[`<lens-selections page=${JSON.stringify(n.title)} url=${JSON.stringify(n.url)}>`,`What the developer selected on the page, from TAW’s source map:`,...r,`</lens-selections>`].join(`
`)}function Nn(e,t){return e?t.map(t=>{let n=t.attribution,r=n.kind===`block`?e.nodes.get(n.node):void 0,i=n.kind===`field`?pn(n.source.read)?L(`WordPress`,`taw-core`):L(`field`,`taw-core`):n.kind===`template`?L(`template text`,`taw-core`):n.kind===`block`?r?.kind===`page`?L(`page`,`taw-core`):r?.kind===`part`?L(`template part`,`taw-core`):L(`block`,`taw-core`):n.kind===`ambiguous`?L(`ambiguous`,`taw-core`):n.kind===`mixed`?L(`mixed`,`taw-core`):L(`element`,`taw-core`);return`${n.kind===`element`?bn(e,t):xn(e,t)} · ${i}`}):[]}var Pn={STRONG:`strong`,B:`strong`,EM:`em`,I:`em`},Fn=[`href`,`target`,`rel`],In=new Set([`SPAN`,`FONT`]),Ln=new Set([`P`,`DIV`]),Rn=e=>e.replace(/&/g,`&amp;`).replace(/</g,`&lt;`).replace(/>/g,`&gt;`),zn=e=>Rn(e).replace(/"/g,`&quot;`),Bn=e=>e.replace(/[\t\n\r\f ]+/g,` `);function Vn(e){let t=e;for(let e=``;e!==t;)e=t,t=t.replace(/<(strong|em)>\s*<\/\1>/g,``).replace(/<\/(strong|em)><\1>/g,``);return t.replace(/ *<br> */g,`<br>`).replace(/^(?: |<br>)+|(?: |<br>)+$/g,``).trim()}var Hn=class{formats;lossy=!1;constructor(e){this.formats=e}inline(e){if(e.nodeType===Node.TEXT_NODE)return Rn(Bn(e.data));if(e.nodeType!==Node.ELEMENT_NODE)return``;let t=e,n=()=>[...t.childNodes].map(e=>this.inline(e)).join(``);if(t.tagName===`BR`)return`<br>`;let r=Pn[t.tagName];return r?((!this.formats.has(r===`strong`?`bold`:`italic`)||t.attributes.length>0)&&(this.lossy=!0),this.formats.has(r===`strong`?`bold`:`italic`)?`<${r}>${n()}</${r}>`:n()):t.tagName===`A`&&t.hasAttribute(`href`)?((!this.formats.has(`link`)||[...t.attributes].some(e=>!Fn.includes(e.name)))&&(this.lossy=!0),this.formats.has(`link`)?`<a${Fn.filter(e=>t.hasAttribute(e)).map(e=>` ${e}="${zn(t.getAttribute(e)??``)}"`).join(``)}>${n()}</a>`:n()):((!In.has(t.tagName)||t.attributes.length>0)&&(this.lossy=!0),n())}blocks(e){let t=[],n=``,r=()=>{let e=Vn(n);e!==``&&t.push({type:`p`,html:e}),n=``};for(let i of[...e.childNodes]){let e=i.nodeType===Node.ELEMENT_NODE?i:null;if(e&&Ln.has(e.tagName)){r(),e.attributes.length>0&&(this.lossy=!0),t.push(...this.blocks(e));continue}if(e&&(e.tagName===`UL`||e.tagName===`OL`)){r(),(!this.formats.has(`lists`)||e.attributes.length>0)&&(this.lossy=!0);let n=[...e.children].filter(e=>e.tagName===`LI`).map(e=>Vn([...e.childNodes].map(e=>this.inline(e)).join(``))).filter(e=>e!==``);e.children.length!==n.length&&(this.lossy=!0),this.formats.has(`lists`)?n.length>0&&t.push({type:e.tagName===`UL`?`ul`:`ol`,items:n}):n.forEach(e=>t.push({type:`p`,html:e}));continue}n+=this.inline(i)}return r(),t}},Un=(e,t)=>{let n=t.createElement(`template`);return n.innerHTML=e,n.content};function Wn(e,t,n=document){let r=new Hn(new Set(t));return{blocks:r.blocks(Un(e,n)),lossy:r.lossy}}function Gn(e,t,n=document){return Wn(/<p[\s>]/i.test(e)?e:e.replace(/\r\n?/g,`
`).replace(/\s*(<(ul|ol)[\s>][\s\S]*?<\/\2>)\s*/gi,`

$1

`).split(/\n\s*\n/).map(e=>e.trim()).filter(e=>e!==``).map(e=>/^<(ul|ol)[\s>]/i.test(e)?e.replace(/\s*(<\/?(?:ul|ol|li)(?:\s[^>]*)?>)\s*/gi,`$1`):`<p>${e.replace(/\s*\n\s*/g,`<br>`)}</p>`).join(``),t,n)}var Kn=(e,t)=>JSON.stringify(e)===JSON.stringify(t);function qn(e){return e.map(e=>e.type===`p`?`<p>${e.html}</p>`:`<${e.type}>\n${e.items.map(e=>`<li>${e}</li>`).join(`
`)}\n</${e.type}>`).join(`

`)}var Jn=()=>window.wp?.autop?.removep;function Yn(e,t=Jn()??null){if(t)return t(qn(e)).trim();let n=``;return e.forEach((t,r)=>{r>0&&(n+=t.type===`p`&&e[r-1].type===`p`?`

`:`
`),n+=t.type===`p`?t.html.replace(/<br>/g,`
`):`<${t.type}>\n${t.items.map(e=>` \t<li>${e.replace(/<br>/g,`
`)}</li>`).join(`
`)}\n</${t.type}>`}),n}function Xn(e,t=document){return e.map(e=>(Un((e.type===`p`?e.html:e.items.join(` · `)).replace(/<br>/g,` `),t).textContent??``).trim()).join(` ¶ `)}var Zn=new Set([`checkbox`,`toggle`,`color`,`hidden`,`image`,`gallery`,`file`,`post_select`]),Qn=new Set([`SCRIPT`,`STYLE`,`NOSCRIPT`,`TEMPLATE`,`HEAD`,`TITLE`]),$n=new Set([`HTML`,`HEAD`,`BODY`]),er=4e3,$=e=>e.replace(/\s+/g,` `).trim();function tr(e,t=document){let n=t.createElement(`template`);return n.innerHTML=e,$(n.content.textContent??``)}var nr=(e,t)=>e===`wysiwyg`||/<[a-z][^>]*>/i.test(t);function rr(e){let t=e;if(typeof t==`string`&&/^\s*\[/.test(t))try{t=JSON.parse(t)}catch{return null}return Array.isArray(t)?t.filter(e=>typeof e==`object`&&!!e):null}var ir=new WeakMap;function ar(e,t=document){let n=ir.get(e);if(n)return n;let r=[];for(let n of e.reads){if(Zn.has(n.type))continue;let i=n.type===`repeater`||n.subs?rr(n.value):null;if(i){i.forEach((i,a)=>{for(let[o,s]of Object.entries(i)){if(typeof s!=`string`&&typeof s!=`number`)continue;let i=String(s),c=n.subs?.[o]?.type??`text`;if(!i.trim()||Zn.has(c))continue;let l=n.subs?.[o]?.label??o,u=nr(c,i);r.push({source:{kind:`field`,node:e.id,read:n,path:`${n.key}[${a}].${o}`,label:`${n.label} › ${l} (row ${a+1})`},text:u?tr(i,t):$(i),rich:u})}});continue}if(typeof n.value!=`string`&&typeof n.value!=`number`)continue;let a=String(n.value);if(!a.trim())continue;let o=nr(n.type,a);r.push({source:{kind:`field`,node:e.id,read:n,path:n.key,label:n.label},text:o?tr(a,t):$(a),rich:o})}return ir.set(e,r),r}function or(e,t){let n=t.template;return n?(e.files[n]?.texts??[]).map(e=>({kind:`template`,node:t.id,file:n,line:e.line,text:$(e.text),how:e.kind})):[]}function sr(e){return e.kind===`field`?`f:${e.read.store}:${e.read.object}:${e.path}`:`t:${e.file}:${e.line}:${e.text}`}var cr=e=>`f:${e.store}:${e.object}:${e.key}`;function lr(e){let t=new Map;for(let n of e){let e=sr(n);t.has(e)||t.set(e,n)}return[...t.values()]}function ur(e){let t=new Set(e.flatMap(e=>e.kind===`field`&&e.read.store===`menu`&&e.read.target?[e.read.target]:[]));return t.size===0?e:e.filter(e=>!(e.kind===`field`&&e.read.store===`post`&&e.read.key===`post_title`&&t.has(Number(e.read.object))))}function dr(e,t,n,r=document){let i=fn(e,t);for(let t of i){let i=[],a=[];for(let e of ar(t,r))e.text===n?i.push(e.source):e.rich&&n.length>=2&&e.text.includes(n)&&a.push({...e.source,part:!0});for(let r of or(e,t))r.text===n&&i.push(r);let o=lr(ur(i.length>0?i:a));if(o.length===1){let[e]=o;return e.kind===`field`?{kind:`field`,node:t.id,text:n,source:e}:{kind:`template`,node:t.id,text:n,source:e}}if(o.length>1)return{kind:`ambiguous`,node:t.id,text:n,sources:o}}for(let t of i){let i=[];for(let e of ar(t,r))e.text.length>=2&&n.includes(e.text)&&i.push(e.source);for(let r of or(e,t))r.text.length>=2&&n.includes(r.text)&&i.push(r);if(i.length>0)return{kind:`mixed`,node:t.id,text:n,sources:lr(i)}}return{kind:`element`,node:t,text:n}}function fr(e){let t=``,n=e.ownerDocument.createTreeWalker(e,NodeFilter.SHOW_TEXT);for(let e=n.nextNode();e;e=n.nextNode())e.parentElement?.closest(`script,style,noscript,template`)||(t+=e.data);return $(t)}var pr=class{manifest;doc;nodeByElement=new Map;roots=new Map;bySource=new Map;sourcesByKey=new Map;attributions=new Map;order=new Map;constructor(e,t,n){this.doc=e,this.manifest=t,this.scan(n??null)}nodeOf(e){return this.nodeByElement.get(e)??0}rootsOf(e){return this.roots.get(e)??[]}elementsOf(e){return this.bySource.get(e)??[]}sources(){return this.sourcesByKey}position(e){return this.order.get(e)??2**53-1}attribute(e){let t=this.attributions.get(e);if(t)return t;let n=this.compute(e);return this.attributions.set(e,n),n}isRoot(e){let t=this.nodeOf(e),n=e.parentElement;return t!==0&&(!n||$n.has(n.tagName)||this.nodeOf(n)!==t)}compute(e){if($n.has(e.tagName))return{kind:`block`,node:0};let t=this.nodeOf(e);if(e.tagName===`IMG`){let n=this.imageField(e,t);if(n)return{kind:`field`,node:n.node,text:``,source:n}}let n=fr(e),r=n&&n.length<=er?dr(this.manifest,t,n,this.doc):null;if(r&&(r.kind===`field`||r.kind===`template`||r.kind===`ambiguous`))return r;if(this.isRoot(e))return{kind:`block`,node:t};let i=e.tagName===`A`?this.linkField(e,t):null;return i?{kind:`field`,node:i.node,text:n,source:i}:r?.kind===`mixed`?r:{kind:`element`,node:t,text:n}}imageField(e,t){let n=`${e.getAttribute(`src`)??``} ${e.getAttribute(`srcset`)??``}`;for(let e of fn(this.manifest,t)){let t=e.reads.find(e=>e.type===`image`&&e.stem&&n.includes(`/${e.stem}`));if(t)return{kind:`field`,node:e.id,read:t,path:t.key,label:t.label}}return null}linkField(e,t){let n=e.getAttribute(`href`);if(!n)return null;for(let e of fn(this.manifest,t)){let t=e.reads.find(e=>(e.type===`url`||e.type===`link`)&&typeof e.value==`string`&&e.value===n);if(t)return{kind:`field`,node:e.id,read:t,path:t.key,label:t.label}}return null}add(e,t){let n=this.bySource.get(e);n?n.includes(t)||n.push(t):this.bySource.set(e,[t])}scan(e){let t=[0],n=new Set,r=this.doc.createTreeWalker(this.doc,NodeFilter.SHOW_COMMENT|NodeFilter.SHOW_ELEMENT|NodeFilter.SHOW_TEXT),i=0;for(let a=r.nextNode();a;a=r.nextNode()){if(a.nodeType===Node.COMMENT_NODE){let e=/^(\/)?taw-lens:(\d+)$/.exec(a.data);if(e){if(e[1]){let n=t.lastIndexOf(Number(e[2]));n>0&&(t.length=n)}else t.push(Number(e[2]))}continue}if(a.nodeType===Node.ELEMENT_NODE){let n=a;if(e&&(n===e||e.contains(n)))continue;this.nodeByElement.set(n,t[t.length-1]),this.order.set(n,i++);continue}let r=a.parentElement;r&&$(a.data)&&!Qn.has(r.tagName)&&!r.closest(`script,style,noscript,template,head`)&&!(e&&e.contains(r))&&n.add(r)}for(let[e,t]of this.nodeByElement)if(t!==0&&this.isRoot(e)&&!Qn.has(e.tagName)&&e.tagName!==`LINK`&&e.tagName!==`META`){let n=this.roots.get(t)??[];n.push(e),this.roots.set(t,n)}for(let[e,t]of this.nodeByElement)if(t===0&&!$n.has(e.tagName)&&!Qn.has(e.tagName)&&e.parentElement){let t=e.parentElement;if($n.has(t.tagName)||this.nodeOf(t)!==0){let t=this.roots.get(0)??[];t.push(e),this.roots.set(0,t)}}let a=[...new Set([...n,...this.doc.querySelectorAll(`img, a[href]`)])].filter(t=>!(e&&e.contains(t)));for(let e of a){let t=this.attribute(e),n=t.kind===`field`||t.kind===`template`?[t.source]:t.kind===`ambiguous`?t.sources:[];for(let t of n){let n=sr(t);this.add(n,e),this.sourcesByKey.has(n)||this.sourcesByKey.set(n,t),t.kind===`field`&&this.add(cr(t.read),e)}}}},mr=/^(.+)\[(\d+)\]\.(.+)$/,hr=[`bold`,`italic`,`link`,`lists`];function gr(e,t){let n=mr.exec(t),r=n?e.subs?.[n[3]]:e,i=(r?.formats??[]).filter(e=>hr.includes(e));return{edit:r?.edit===`text`||r?.edit===`rich`?r.edit:void 0,formats:i,type:r?.type??e.type}}function _r(e,t){let n=e.value,r=mr.exec(t);if(r)try{let e=typeof n==`string`?JSON.parse(n):n;n=(Array.isArray(e)?e.filter(e=>typeof e==`object`&&!!e):[])[Number(r[2])]?.[r[3]]}catch{return null}return typeof n==`string`||typeof n==`number`?String(n):null}var vr=e=>e.childNodes.length>0&&[...e.childNodes].every(e=>e.nodeType===Node.TEXT_NODE||e.nodeType===Node.COMMENT_NODE),yr=(e,t)=>e.kind===`field`&&sr(e.source)===sr(t),br=()=>L(`The page shows this field differently from how it’s stored, so Lens can’t edit it here. Open it in wp-admin.`,`taw-core`);function xr(e,t,n,r,i){let a=Gn(r,i,t.ownerDocument);if(a.lossy)return{reason:L(`This field has formatting Lens can’t edit on the page, such as images or headings. Open it in wp-admin.`,`taw-core`)};let o=null;for(let r=t;r&&![`BODY`,`HTML`].includes(r.tagName);r=r.parentElement){let t=e.attribute(r);if(!yr(t,n)||t.source.part){if(o)break;continue}let s=Wn(r.innerHTML,i,r.ownerDocument);if(!s.lossy&&Kn(s.blocks,a.blocks))o=r;else if(o)break}return o?{plan:{kind:`field`,mode:`rich`,source:n,el:o,original:r,formats:i,blocks:a.blocks}}:{reason:br()}}function Sr(e,t){let n=t.attribution,r=t.elements[0];if(!r)return null;if(n.kind===`template`)return!vr(r)||fr(r)!==n.source.text?{reason:L(`This text shares its element with other markup. Ask Claude to change it.`,`taw-core`)}:{plan:{kind:`template`,source:n.source,el:r,original:fr(r)}};if(n.kind!==`field`)return null;let i=n.source,a=i.read;if(a.store===`wp`||a.store===`menu`||a.type===`wp`)return null;let o=gr(a,i.path);if(!o.edit)return a.type===`image`||o.type===`image`?null:{reason:L(`This kind of field can’t be edited on the page. Open it in wp-admin.`,`taw-core`)};let s=_r(a,i.path);if(s===null)return{reason:br()};let c=i.part?{...i,part:void 0}:i;return o.edit===`rich`?xr(e,r,c,s,o.formats??[]):i.part?{reason:br()}:/[\r\n]/.test(s.trim())?{reason:L(`This text has line breaks, which editing on the page would lose. Open it in wp-admin.`,`taw-core`)}:!vr(r)||$(s)!==fr(r)?{reason:br()}:{plan:{kind:`field`,mode:`text`,source:i,el:r,original:s}}}function Cr(e,t,n){let r=mr.exec(e.path);return{store:e.read.store,object:e.read.object,key:e.read.key,...r?{row:Number(r[2]),sub:r[3]}:{},value:t,expect:n}}function wr(e,t,n,r,i){let a=n.text,o=e=>e.replace(/\n/g,` `);return{text:L(`Change "%1$s" to "%2$s".`,`taw-core`).replace(`%1$s`,a).replace(`%2$s`,r),context:[`<lens-edit file="${n.file}" line="${n.line}">`,`Before: ${o(a)}`,`After: ${o(r)}`,`</lens-edit>`,``,Mn(e,[t],i)].join(`
`),chips:Nn(e,[t]),from:`edit`}}var Tr=typeof navigator<`u`&&/Mac|iPhone|iPad/.test(navigator.platform||navigator.userAgent)?`⌘`:`Ctrl+`,Er=e=>e.preventDefault();function Dr({formats:e,state:t,linkOpen:n,onFormat:r,onLink:i}){let a=(e,t,n,r)=>X(`button`,{type:`button`,class:`tool`,"aria-label":e,title:e,"aria-pressed":t,onMouseDown:Er,onClick:n,children:r});return X(`div`,{class:`edit-toolbar`,role:`toolbar`,"aria-label":L(`Formatting`,`taw-core`),children:[e.includes(`bold`)&&a(L(`Bold`,`taw-core`),t.bold,()=>r(`bold`),X(`span`,{class:`tool-b`,children:`B`})),e.includes(`italic`)&&a(L(`Italic`,`taw-core`),t.italic,()=>r(`italic`),X(`span`,{class:`tool-i`,children:`I`})),e.includes(`link`)&&a(L(`Link`,`taw-core`),n||t.link,i,X(It,{})),e.includes(`lists`)&&X(v,{children:[X(`span`,{class:`tool-rule`,"aria-hidden":`true`}),a(L(`Bulleted list`,`taw-core`),t.ul,()=>r(`ul`),X(Lt,{})),a(L(`Numbered list`,`taw-core`),t.ol,()=>r(`ol`),X(Rt,{}))]}),X(`span`,{class:`tool-note`,children:L(`Allowed by this field`,`taw-core`)})]})}function Or({link:e,pages:t,onUrl:n,onApply:r,onClose:i}){let[a,o]=U(e.url),s=G(null);return W(()=>s.current?.focus(),[]),X(`div`,{class:`edit-link`,children:[X(`label`,{for:`lens-link-url`,class:`edit-link-label`,children:e.text?L(`Link for "%s"`,`taw-core`).replace(`%s`,Q(e.text,40)):L(`Link`,`taw-core`)}),X(`div`,{class:`edit-link-row`,children:[X(`input`,{id:`lens-link-url`,class:`edit-link-input mono`,type:`text`,list:`lens-link-pages`,value:a,placeholder:`/about/`,autoComplete:`off`,ref:s,onInput:e=>{let t=e.currentTarget.value;o(t),n(t)},onKeyDown:e=>{e.key===`Enter`?(e.preventDefault(),r(a.trim())):e.key===`Escape`&&(e.preventDefault(),e.stopPropagation(),i())}}),X(`datalist`,{id:`lens-link-pages`,children:t.map(e=>X(`option`,{value:e.url,children:e.title},e.url))}),X(`button`,{type:`button`,class:`card-button`,"aria-disabled":a.trim()===``?`true`:void 0,onClick:()=>a.trim()!==``&&r(a.trim()),children:e.existing?L(`Update link`,`taw-core`):L(`Add link`,`taw-core`)}),e.existing&&X(`button`,{type:`button`,class:`card-button`,onClick:()=>r(``),children:L(`Remove`,`taw-core`)})]}),X(`span`,{class:`edit-hint`,children:L(`Pages on this site are suggested as you type.`,`taw-core`)})]})}function kr(e){let{plan:t,after:n,before:r,saving:i,error:a,blocked:o}=e,s=t.kind===`field`&&t.mode===`rich`,c=t.kind===`field`?hn(t.source.read,t.source.path):`“${Q(t.source.text,48)}”`,l=t.kind===`field`?`${L(`Field`,`taw-core`)} · ${mn(t.mode===`rich`?`wysiwyg`:t.source.read.subs?.[t.source.path.split(`.`).pop()??``]?.type??t.source.read.type)}`:t.source.how===`i18n`?L(`Template text · translatable`,`taw-core`):L(`Template text`,`taw-core`),u=t.kind===`template`?L(`Type on the page. Template text lives in a theme file, so Save sends the change to Claude, and you apply the diff.`,`taw-core`):L(`Type on the page. Saving writes the field value directly; nothing else changes.`,`taw-core`),d=n!==null&&!i&&o===null;return X(`section`,{class:`card edit`,"aria-label":L(`Editing on the page`,`taw-core`),children:[X(`div`,{class:`card-head`,children:[X(`span`,{class:t.kind===`field`?`card-title mono`:`card-title`,children:c}),X(`span`,{class:`card-badge`,children:l}),X(`span`,{class:`edit-status`,children:L(`Editing on the page`,`taw-core`)})]}),X(`div`,{class:`card-location`,children:u}),s&&X(Dr,{formats:t.formats,state:e.state,linkOpen:e.link!==null,onFormat:e.onFormat,onLink:()=>e.link?e.onLinkClose():e.onLinkOpen()}),s&&e.link&&X(Or,{link:e.link,pages:e.pages,onUrl:e.onLinkUrl,onApply:e.onLinkApply,onClose:e.onLinkClose}),n!==null&&X(`div`,{class:`edit-change`,"aria-label":L(`Change`,`taw-core`),children:[X(`div`,{class:`edit-before`,children:[X(`span`,{class:`sr-only`,children:[L(`Before:`,`taw-core`),` `]}),Q(r,400)]}),X(`div`,{class:`edit-after`,children:[X(`span`,{class:`sr-only`,children:[L(`After:`,`taw-core`),` `]}),Q(n,400)||L(`(empty)`,`taw-core`)]})]}),s&&X(`div`,{class:`edit-hint edit-note`,children:L(`Saves in TAW’s rich-text format, the same the wp-admin editor writes.`,`taw-core`)}),(a||o)&&X(`div`,{class:`edit-error`,role:a?`alert`:void 0,children:a??o}),X(`div`,{class:`card-actions edit-actions`,children:[X(`button`,{type:`button`,class:`card-button primary`,"aria-disabled":d?void 0:`true`,onMouseDown:Er,onClick:()=>d&&e.onSave(),children:i?X(v,{children:[X(Mt,{size:12}),t.kind===`template`?L(`Sending…`,`taw-core`):L(`Saving…`,`taw-core`)]}):L(`Save`,`taw-core`)}),X(`button`,{type:`button`,class:`card-button`,onMouseDown:Er,onClick:e.onCancel,children:L(`Cancel`,`taw-core`)}),X(`span`,{class:`edit-keys`,children:s?X(v,{children:[X(`span`,{class:`mono`,children:[Tr,`B`]}),` `,X(`span`,{class:`mono`,children:[Tr,`I`]}),` `,t.formats.includes(`link`)&&X(`span`,{class:`mono`,children:[Tr,`K`]})]}):X(v,{children:[X(`span`,{class:`mono`,children:`Enter`}),` `,L(`save`,`taw-core`),` · `,X(`span`,{class:`mono`,children:`Esc`}),` `,L(`cancel`,`taw-core`)]})})]})]})}function Ar({title:e,mono:t,badge:n,onClose:r}){return X(`div`,{class:`card-head`,children:[X(`span`,{class:t?`card-title mono`:`card-title`,children:e}),X(`span`,{class:`card-badge`,children:n}),r&&X(`button`,{type:`button`,class:`card-close`,"aria-label":L(`Clear selection`,`taw-core`),onClick:r,children:X(St,{})})]})}var jr=({children:e})=>X(`div`,{class:`card-actions`,children:e});function Mr({edit:e,onEdit:t,onWhy:n}){if(!e)return null;let r=`plan`in e;return X(`button`,{type:`button`,class:`card-button primary`,"aria-disabled":r?void 0:`true`,title:r?L(`Edit the text on the page (or double-click it)`,`taw-core`):e.reason,onClick:()=>r?t?.():n(e.reason),children:L(`Edit here`,`taw-core`)})}var Nr=({href:e,children:t,primary:n})=>X(`a`,{class:n?`card-button primary`:`card-button`,href:e,target:`_blank`,rel:`noopener noreferrer`,children:t});function Pr(e){let t=e.read.value,n=/\[(\d+)\]\.(.+)$/.exec(e.path);if(n)try{let e=typeof t==`string`?JSON.parse(t):t;t=Array.isArray(e)?e[Number(n[1])]?.[n[2]]:t}catch{}else if(e.read.type===`repeater`)try{let e=typeof t==`string`?JSON.parse(t):t;if(Array.isArray(e))return e.length===1?L(`1 row`,`taw-core`):L(`%d rows`,`taw-core`).replace(`%d`,String(e.length))}catch{}if(e.read.type===`image`)return e.read.stem??String(t??``);let r=typeof t==`string`||typeof t==`number`?String(t):JSON.stringify(t??``);return Q(/<[a-z][^>]*>/i.test(r)?tr(r):r,600)}function Fr({manifest:e,sources:t,editor:n}){return X(`ul`,{class:`card-sources`,children:t.map(t=>X(`li`,{children:[X(`span`,{class:`card-source-name mono`,children:gn(t)}),X(`span`,{class:`card-source-where`,children:t.kind===`field`?vn(e,t.node,t.label):`${t.file}:${t.line}`}),t.kind===`field`?t.read.admin&&X(`a`,{class:`card-link`,href:t.read.admin,target:`_blank`,rel:`noopener noreferrer`,children:L(`Open in wp-admin`,`taw-core`)}):X(Ir,{manifest:e,source:t,editor:n})]},t.kind===`field`?t.path+t.read.object:`${t.file}:${t.line}`))})}function Ir({manifest:e,source:t,editor:n}){let r=e.files[t.file]?.abs;return r?X(`a`,{class:`card-link`,href:Sn(n,r,t.line),children:L(`Open in editor`,`taw-core`)}):null}function Lr({manifest:e,node:t}){let n=e.nodes.get(t)?.template;return n?X(`div`,{class:`card-meta mono`,children:[L(`Rendered by`,`taw-core`),` `,n]}):null}function Rr({manifest:e,target:t,editor:n,edit:r,onEdit:i,onClose:a,onShowInTree:o}){let s=t.attribution,c=e.nodes.get(t.at),[l,u]=U(null);W(()=>u(null),[t]);let d=X(Mr,{edit:r,onEdit:i,onWhy:u}),f=l&&X(`div`,{class:`card-note card-why`,role:`status`,children:l});if(s.kind===`field`){let{read:n}=s.source,r=pn(n),i=hn(n,s.source.path),o=r?L(`WordPress`,`taw-core`):`${L(`Field`,`taw-core`)} · ${mn(n.subs?.[s.source.path.split(`.`).pop()??``]?.type??n.type)}`;return X(`section`,{class:`card`,"aria-label":L(`Inspect`,`taw-core`),children:[X(Ar,{title:i,mono:!r,badge:o,onClose:a}),X(`div`,{class:`card-location`,children:vn(e,s.source.node,s.source.label)}),X(`div`,{class:`card-value`,children:Pr(s.source)||X(`span`,{class:`card-empty`,children:L(`(empty)`,`taw-core`)})}),n.default&&X(`div`,{class:`card-note`,children:L(`The field’s default: nothing is saved yet.`,`taw-core`)}),s.source.part&&X(`div`,{class:`card-note`,children:L(`Part of this rich-text field.`,`taw-core`)}),X(Lr,{manifest:e,node:t.at}),f,X(jr,{children:[r?null:d,n.admin&&X(Nr,{href:n.admin,children:L(`Open in wp-admin`,`taw-core`)})]})]})}if(s.kind===`template`){let{source:t}=s,r=e.files[t.file],i=r?.lines??{},o=[t.line-1,t.line,t.line+1].filter(e=>i[String(e)]!==void 0);return X(`section`,{class:`card`,"aria-label":L(`Inspect`,`taw-core`),children:[X(Ar,{title:`“${Q(t.text,60)}”`,badge:t.how===`i18n`?L(`Template text · translatable`,`taw-core`):L(`Template text`,`taw-core`),onClose:a}),X(`div`,{class:`card-location`,children:vn(e,t.node)}),o.length>0&&X(`div`,{class:`card-code mono`,role:`group`,"aria-label":`${t.file}:${t.line}`,children:o.map(e=>X(`div`,{class:`card-code-line`,"data-current":e===t.line?``:void 0,children:[X(`span`,{class:`card-code-number`,children:e}),X(`span`,{class:`card-code-text`,children:i[String(e)]})]},e))}),X(`div`,{class:`card-meta mono`,children:[t.file,`:`,t.line]}),f,X(jr,{children:[d,r?.abs&&X(Nr,{href:Sn(n,r.abs,t.line),children:L(`Open in editor`,`taw-core`)})]})]})}if(s.kind===`block`){let r=e.nodes.get(s.node);if(!r)return null;let i=r.kind===`block`?`${L(`Block`,`taw-core`)} · ${r.name}`:r.kind===`part`?L(`Template part`,`taw-core`):L(`Page`,`taw-core`),c=r.files??(r.template?[r.template]:[]),l=[...new Set(r.reads.filter(e=>!pn(e)&&e.store!==`option`).map(e=>e.key))],u=r.template?e.files[r.template]:void 0;return X(`section`,{class:`card`,"aria-label":L(`Inspect`,`taw-core`),children:[X(Ar,{title:_n(r),badge:i,onClose:a}),X(`div`,{class:`card-location`,children:vn(e,r.id)}),X(`ul`,{class:`card-files mono`,children:c.map(t=>{let r=e.files[t]?.abs;return X(`li`,{children:r?X(`a`,{href:Sn(n,r),children:t}):t},t)})}),l.length>0&&X(`div`,{class:`card-meta`,children:[L(`Fields:`,`taw-core`),` `,l.join(`, `)]}),X(jr,{children:[u?.abs&&X(Nr,{href:Sn(n,u.abs),children:L(`Open in editor`,`taw-core`)}),X(`button`,{type:`button`,class:`card-button`,onClick:()=>o(t),children:L(`Show in tree`,`taw-core`)})]})]})}if(s.kind===`ambiguous`)return X(`section`,{class:`card`,"aria-label":L(`Inspect`,`taw-core`),children:[X(Ar,{title:`“${Q(s.text,60)}”`,badge:L(`Ambiguous`,`taw-core`),onClose:a}),X(`div`,{class:`card-location`,children:vn(e,s.node)}),X(`div`,{class:`card-note`,children:L(`More than one source holds this exact text, so Lens can’t tell which one rendered it. It doesn’t guess:`,`taw-core`)}),X(Fr,{manifest:e,sources:s.sources,editor:n})]});if(s.kind===`mixed`)return X(`section`,{class:`card`,"aria-label":L(`Inspect`,`taw-core`),children:[X(Ar,{title:`“${Q(s.text,60)}”`,badge:L(`Mixed`,`taw-core`),onClose:a}),X(`div`,{class:`card-location`,children:vn(e,t.at)}),X(`div`,{class:`card-note`,children:L(`Template text around values. Made of:`,`taw-core`)}),X(Fr,{manifest:e,sources:s.sources,editor:n}),X(Lr,{manifest:e,node:t.at})]});let p=t.elements[0]?.tagName.toLowerCase()??``,m=c?.template?e.files[c.template]:void 0;return X(`section`,{class:`card`,"aria-label":L(`Inspect`,`taw-core`),children:[X(Ar,{title:`<${p}>`,mono:!0,badge:L(`Element`,`taw-core`),onClose:a}),X(`div`,{class:`card-location`,children:vn(e,t.at)}),X(`div`,{class:`card-note`,children:s.text?L(`Its text isn’t a TAW field or template text: it may be computed in the template, or added by JavaScript.`,`taw-core`):L(`No text of its own. Its template rendered it.`,`taw-core`)}),X(Lr,{manifest:e,node:t.at}),X(jr,{children:m?.abs&&X(Nr,{href:Sn(n,m.abs),children:L(`Open in editor`,`taw-core`)})})]})}function zr(e,t,n=400){return e<120?`left`:e+n>t.width-120?`right`:null}var Br=e=>Math.max(240,Math.min(600,e.height-e.top-24));function Vr(e){return Hr({x:e.width-400-24,y:e.top+72},e)}function Hr(e,t,n=400){let r=Math.max(12,t.width-n-12),i=t.top+12,a=Math.max(i,t.height-Br(t)-12);return{x:Math.round(Math.min(r,Math.max(12,e.x))),y:Math.round(Math.min(a,Math.max(i,e.y)))}}var Ur=(e,t,n=400)=>e.x+n/2<t.width/2?`left`:`right`;function Wr(e,t=400){return`html { margin-${e}: ${t}px !important; }`}function Gr(e,t,n=400){return{left:t===`left`?n:0,top:e.top,right:t===`right`?e.width-n:e.width,bottom:e.height}}function Kr(e,t,n){let r=Math.max(n.left+1,e.left-t),i=Math.max(n.top+1,e.top-t),a=Math.min(n.right-1,e.left+e.width+t),o=Math.min(n.bottom-1,e.top+e.height+t);return{left:r,top:i,width:Math.max(0,a-r),height:Math.max(0,o-i)}}function qr([e,t,n]){let r=e=>{let t=e/255;return t<=.04045?t/12.92:((t+.055)/1.055)**2.4};return .2126*r(e)+.7152*r(t)+.0722*r(n)}var Jr=`#1A1C21`,Yr=`#FFFFFF`,Xr=[26,28,33],Zr=[255,255,255];function Qr(e){let t=t=>{let[n,r]=e>t?[e,t]:[t,e];return(n+.05)/(r+.05)};return t(qr(Xr))>=t(qr(Zr))?{ring:Jr,halo:Yr,luminance:e}:{ring:Yr,halo:Jr,luminance:e}}var $r=(e,t)=>[e.r*e.a+t[0]*(1-e.a),e.g*e.a+t[1]*(1-e.a),e.b*e.a+t[2]*(1-e.a)];function ei(e){let t=Zr;for(let n=e.length-1;n>=0;n--)t=$r(e[n],t);return t}function ti(e,t){let n=e.trim().toLowerCase();if(n===`transparent`||n===``)return{r:0,g:0,b:0,a:0};let r=/^#([0-9a-f]{3,8})$/.exec(n);if(r){let e=r[1],t=e.length<=4?[...e].map(e=>e+e).join(``):e;if(t.length===6||t.length===8){let e=e=>parseInt(t.slice(e,e+2),16);return{r:e(0),g:e(2),b:e(4),a:t.length===8?e(6)/255:1}}}let i=/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/.exec(n);if(i){let e=i[4]===void 0?1:i[4].endsWith(`%`)?parseFloat(i[4])/100:parseFloat(i[4]);return{r:+i[1],g:+i[2],b:+i[3],a:Math.max(0,Math.min(1,e))}}return t?t(e):null}function ni(e){return e.match(/(?:rgba?|hsla?|oklch|oklab|lab|lch|color)\([^()]*\)|#[0-9a-f]{3,8}\b/gi)??[]}function ri(e){if(e.length===0)return null;let t=e.reduce((e,t)=>({r:e.r+t.r,g:e.g+t.g,b:e.b+t.b,a:e.a+t.a}),{r:0,g:0,b:0,a:0}),n=e.length;return{r:t.r/n,g:t.g/n,b:t.b/n,a:t.a/n}}function ii(e,t,n){let r=e.left-t,i=e.left+e.width+t,a=e.top-t,o=e.top+e.height+t,s=e.left+e.width/2,c=e.top+e.height/2;return[[r,a],[s,a],[i,a],[i,c],[i,o],[s,o],[r,o],[r,c]].map(([e,t])=>[Math.round(e),Math.round(t)]).filter(([e,t])=>e>=0&&t>=0&&e<n.width&&t<n.height)}var ai=new Set([`IMG`,`VIDEO`,`CANVAS`]);function oi(e,t,n){let r=[];for(let i of e.stackAt(t,n)){let a=e.style(i);if(a.visibility===`hidden`||parseFloat(a.opacity)===0)continue;if(ai.has(i.tagName)){let a=e.media(i,t,n);if(a&&(r.push(a),a.a>=.999))break;continue}let o=a.backgroundImage;if(o&&o!==`none`){let a=/url\((['"]?)(.*?)\1\)/.exec(o)?.[2],s=(a?e.backgroundImage(i,a,t,n):null)??ri(ni(o).flatMap(t=>e.color(t)??[]));if(s&&s.a>0&&(r.push(s),s.a>=.999))break}let s=e.color(a.backgroundColor);if(s&&s.a>0&&(r.push(s),s.a>=.999))break}return ei(r)}function si(e,t,n,r){let i=ii(t,n,r),a=(i.length>0?i:[[Math.round(t.left+t.width/2),Math.round(t.top+t.height/2)]]).map(([t,n])=>qr(oi(e,t,n)));return Qr(a.reduce((e,t)=>e+t,0)/a.length)}var ci={hover:4,selected:7,editing:7},li=24;function ui({rings:e,sampler:t,top:n,area:r,refresh:i=0}){let[a,o]=De(e=>e+1,0),s=G(new Map),c=e.length>0,l=G(i);W(()=>{if(!c)return;let e=0,t=0,n=()=>{e||=requestAnimationFrame(()=>{e=0,o(void 0)}),clearTimeout(t),t=window.setTimeout(()=>{s.current.clear(),o(void 0)},150)};window.addEventListener(`scroll`,n,{capture:!0,passive:!0}),window.addEventListener(`resize`,n,{passive:!0});let r=window.setInterval(()=>o(void 0),600);return()=>{cancelAnimationFrame(e),clearTimeout(t),clearInterval(r),window.removeEventListener(`scroll`,n,{capture:!0}),window.removeEventListener(`resize`,n)}},[c]);let u={width:document.documentElement.clientWidth||window.innerWidth,height:window.innerHeight},d=ke(()=>(l.current!==i&&(l.current=i,s.current.clear()),e.flatMap(e=>{if(!e.el.isConnected)return[];let n=e.el.getBoundingClientRect();if(n.width<=0&&n.height<=0)return[];let r=ci[e.state];if(n.bottom+r<0||n.top-r>u.height)return[];let i=s.current.get(e.el);return i||(i=t?si(t,n,r,u):{ring:`#1A1C21`,halo:`#FFFFFF`,luminance:1},s.current.set(e.el,i)),[{spec:e,rect:n,outset:r,color:i}]})),[e,t,a,i,u.width,u.height]);return d.length===0?null:X(`div`,{class:`overlay`,"aria-hidden":`true`,children:d.map(({spec:e,rect:t,outset:i,color:a})=>{let o=r??{left:0,top:n,right:u.width,bottom:u.height},s=Kr(t,i,o);if(s.width<=0||s.height<=0)return null;let c=s.top-6-li,l=c>=o.top+2?c:Math.min(s.top+s.height+6,o.bottom-li-2),d=Math.max(o.left+4,Math.min(s.left,o.right-120));return X(`div`,{children:[X(`div`,{class:`ring`,"data-state":e.state,style:{left:`${s.left}px`,top:`${s.top}px`,width:`${s.width}px`,height:`${s.height}px`,"--ring":a.ring,"--halo":a.halo}}),e.label&&X(`div`,{class:`ring-label`,style:{left:`${d}px`,top:`${l}px`,maxWidth:`${Math.max(120,o.right-d-8)}px`,"--ring":a.ring,"--halo":a.halo},children:e.label})]},e.key)})})}var di=`taw-lens:prefs`,fi={mode:`docked`,shown:`docked`,side:`right`,sheet:null,theme:`system`},pi=(e,t,n)=>typeof e==`string`&&t.includes(e)?e:n,mi=e=>typeof e==`number`&&Number.isFinite(e);function hi(e){if(typeof e!=`object`||!e)return{...fi};let t=e,n=t.sheet;return{mode:pi(t.mode,[`docked`,`sheet`,`hidden`],fi.mode),shown:pi(t.shown,[`docked`,`sheet`],fi.shown),side:pi(t.side,[`left`,`right`],fi.side),sheet:typeof n==`object`&&n&&mi(n.x)&&mi(n.y)?{x:n.x,y:n.y}:null,theme:pi(t.theme,[`system`,`light`,`dark`],fi.theme)}}function gi(e=vi()){try{let t=e?.getItem(di);return hi(t?JSON.parse(t):null)}catch{return{...fi}}}function _i(e,t=vi()){try{t?.setItem(di,JSON.stringify(e))}catch{}}function vi(){try{return window.localStorage}catch{return null}}var yi=e=>e===`system`?`light`:e===`light`?`dark`:`system`,bi=e=>e?e.split(`/`).pop()??e:``;function xi(e,t){let n=t.id===0?[]:e.rootsOf(t.id),r=t.kind;return{id:`n:${t.id}`,kind:r,name:_n(t),detail:r===`block`?t.name:r===`page`?bi(t.template):t.template??t.name,children:[],target:{key:`n:${t.id}`,attribution:{kind:`block`,node:t.id},elements:n,at:t.id},position:Math.min(...n.map(t=>e.position(t)),2**53-1)}}function Si(e,t,n,r){let i=Math.min(...r.map(t=>e.position(t)),2**53-1),a=r[0]?e.nodeOf(r[0]):n.node;if(n.kind===`template`)return{id:t,kind:`template`,name:Q(n.text,40),detail:`${n.file}:${n.line}`,children:[],target:{key:t,attribution:{kind:`template`,node:n.node,text:n.text,source:n},elements:r,at:a},position:i};let o={kind:`field`,node:n.node,read:n.read,path:n.read.key,label:n.read.label},s=pn(n.read);return{id:t,kind:s?`wp`:`field`,name:hn(n.read),detail:L(s?`WordPress`:`field`,`taw-core`),children:[],target:{key:t,attribution:{kind:`field`,node:n.node,text:``,source:o},elements:r,at:a},position:i}}function Ci(e){let t=new Map;for(let n of e.manifest.nodes.values())t.set(n.id,xi(e,n));let n=new Map;for(let t of e.sources().values()){let e=t.kind===`field`?cr(t.read):sr(t);n.has(e)||n.set(e,{node:t.node,source:t})}for(let[r,{node:i,source:a}]of n){let n=t.get(i),o=e.elementsOf(r);n&&o.length>0&&n.children.push(Si(e,r,a,o))}for(let n of e.manifest.nodes.values())n.parent!==null&&t.get(n.parent)?.children.push(t.get(n.id));let r=e=>{for(let t of e.children)e.position=Math.min(e.position,r(t));let t=e=>e.kind===`page`||e.kind===`part`||e.kind===`block`;return e.children.sort((e,n)=>e.position-n.position||Number(t(n))-Number(t(e))),e.position},i=t.get(0);return r(i),i}var wi=(e,t)=>e.name.toLowerCase().includes(t)||e.detail.toLowerCase().includes(t);function Ti(e,t,n=``){let r=n.trim().toLowerCase(),i=[],a=new Set;if(r){let t=e=>{let n=wi(e,r);for(let r of e.children)n=t(r)||n;return n&&a.add(e.id),n};t(e)}let o=(e,n,s)=>{if(r&&!a.has(e.id))return;let c=r?!0:t.has(e.id);if(i.push({node:e,depth:n,parent:s,expanded:c&&e.children.length>0}),c)for(let t of e.children)o(t,n+1,e.id)};return o(e,0,null),i}function Ei(e,t){let n=[],r=e=>{if(e.id===t)return!0;for(let t of e.children)if(r(t))return n.unshift(e.id),!0;return!1};return r(e)?n:[]}function Di(e){let t=e.attribution;return t.kind===`block`?`n:${t.node}`:t.kind===`field`?cr(t.source.read):t.kind===`template`?sr(t.source):null}var Oi=({kind:e})=>X(e===`page`?Tt:e===`part`?Et:e===`block`?Dt:e===`template`?kt:Ot,{});function ki({root:e,selected:t,reveal:n,onHover:r,onSelect:i,onBack:a}){let[o,s]=U(``),[c,l]=U(()=>new Set([e.id])),[u,d]=U(e.id),f=G(u),p=e=>{f.current=e,d(e)},[m,h]=U(null),g=G(new Map),_=G(!1);W(()=>{let r=[...t,...n?[n]:[]].flatMap(t=>Ei(e,t));n&&r.push(n),r.length>0&&l(e=>new Set([...e,...r])),n&&(p(n),_.current=!0)},[e,n,t]);let v=ke(()=>Ti(e,c,o),[e,c,o]),y=v[Math.max(0,v.findIndex(e=>e.node.id===u))];Oe(()=>{_.current&&y&&(g.current.get(y.node.id)?.focus(),g.current.get(y.node.id)?.scrollIntoView?.({block:`nearest`}),_.current=!1)},[y]);let b=(e,t)=>l(n=>{let r=new Set(n);return t??!r.has(e)?r.add(e):r.delete(e),r}),x=e=>{let t=v[Math.max(0,Math.min(v.length-1,e))];t&&(_.current=!0,p(t.node.id),r(t.node.target))};return X(`div`,{class:`structure`,children:[X(`div`,{class:`structure-head`,children:[X(`span`,{class:`structure-title`,id:`lens-structure-title`,children:L(`Structure`,`taw-core`)}),X(`button`,{type:`button`,class:`text-button`,onClick:a,children:L(`Back to chat`,`taw-core`)})]}),X(`label`,{class:`sr-only`,for:`lens-tree-filter`,children:L(`Filter`,`taw-core`)}),X(`input`,{id:`lens-tree-filter`,class:`filter`,type:`search`,autocomplete:`off`,spellcheck:!1,placeholder:L(`Filter blocks, fields, files…`,`taw-core`),value:o,onInput:e=>s(e.currentTarget.value)}),X(`div`,{class:`tree`,role:`tree`,"aria-labelledby":`lens-structure-title`,onKeyDown:e=>{let t=v.findIndex(e=>e.node.id===f.current),n=Math.max(0,t),r=v[n];if(!r)return;let{node:a,expanded:o,parent:s}=r;switch(e.key){case`ArrowDown`:x(n+1);break;case`ArrowUp`:x(n-1);break;case`Home`:x(0);break;case`End`:x(v.length-1);break;case`ArrowRight`:a.children.length>0&&!o?b(a.id,!0):o&&x(n+1);break;case`ArrowLeft`:o?b(a.id,!1):s&&x(v.findIndex(e=>e.node.id===s));break;case`Enter`:case` `:i(a.target,e.shiftKey);break;default:return}e.preventDefault()},onMouseLeave:()=>{h(null),r(null)},children:[v.map(({node:e,depth:n,expanded:a})=>{let o=t.has(e.id),s=e.target.elements.length>0;return X(`div`,{ref:t=>{t?g.current.set(e.id,t):g.current.delete(e.id)},class:`tree-row`,role:`treeitem`,"aria-level":n+1,"aria-selected":o,"aria-expanded":e.children.length>0?a:void 0,tabIndex:e.id===y?.node.id?0:-1,"data-selected":o?``:void 0,style:{paddingLeft:`${8+n*16}px`},onMouseEnter:()=>{h(e.id),r(e.target)},onFocus:()=>p(e.id),onClick:t=>{p(e.id),i(e.target,t.shiftKey)},children:[e.children.length>0?X(`span`,{class:`tree-twisty`,onClick:t=>{t.stopPropagation(),b(e.id)},children:X(a?Ct:wt,{})}):X(`span`,{class:`tree-twisty`}),X(`span`,{class:`tree-icon`,children:X(Oi,{kind:e.kind})}),X(`span`,{class:`tree-name`,children:e.name}),X(`span`,{class:`tree-detail mono`,children:e.detail}),m===e.id&&s&&X(`span`,{class:`tree-hint`,children:L(`on page`,`taw-core`)})]},e.id)}),v.length===0&&X(`div`,{class:`tree-none`,children:L(`Nothing matches.`,`taw-core`)})]}),X(`div`,{class:`structure-help`,children:L(`Hover a row to ring it on the page. Click to select; shift-click to add it to the chat.`,`taw-core`)})]})}var Ai={bold:!1,italic:!1,link:!1,ul:!1,ol:!1},ji=e=>e.kind===`field`&&e.mode===`rich`?Xn(e.blocks):$(e.original);function Mi(e,t){if(e.kind===`field`&&e.mode===`rich`){let n=Wn(t.html(),e.formats,t.el.ownerDocument).blocks;if(Kn(n,e.blocks))return null;let r=Xn(n,t.el.ownerDocument);return r===Xn(e.blocks,t.el.ownerDocument)?`${r} (${L(`formatting changed`,`taw-core`)})`:r}let n=t.text();return n===$(e.original)?null:n}var Ni=e=>L(`Editing · %s`,`taw-core`).replace(`%s`,e.kind===`field`?hn(e.source.read,e.source.path):`${e.source.file.split(`/`).pop()??e.source.file}:${e.source.line}`),Pi=new WeakMap,Fi=0,Ii=e=>{let t=Pi.get(e);return t||(t=`e:${++Fi}`,Pi.set(e,t)),t},Li=e=>L(e===`light`?`Theme: light. Switch to dark`:e===`dark`?`Theme: dark. Follow the system`:`Theme: follows the system. Switch to light`,`taw-core`),Ri=({theme:e})=>X(e===`light`?yt:e===`dark`?bt:vt,{});function zi({host:e,initial:t,env:n}){let[r,i]=U(t),[a,o]=U(()=>n.viewport()),[s,c]=U(null),[l,u]=U(null),[d,f]=U(``),p=G(null),m=G(null),h=G(null),g=Ae(e=>i(t=>({...t,...e})),[]),_=n.page,[v,y]=U(void 0),b=v??null,[x,S]=U(!1),[C,ee]=U(`chat`),[w,T]=U([]),[E,D]=U(null),[O,k]=U(null),[A,te]=U(null),[ne,re]=U(0),[ie,ae]=U(!0),[j,oe]=U(n.bridge?`connecting`:`off`),[M,N]=U(We),[P,F]=U(``),I=G(null),se=G(j),ce=G(null),le=G(null),ue=G(!0),[de,fe]=U(!1),[pe,me]=U(null),he=G(null),ge=G(null),R=G(!1),_e=G(null),ve=G(Math.random().toString(36).slice(2,10)),ye=ke(()=>{try{return _?.url?new URL(_.url).pathname:``}catch{return``}},[_]),be=G(null),[z,B]=U(null),V=G(null),[xe,Se]=U([]),Ce=G(0),[H,we]=U(null),[Te,Ee]=U([]),De=G(0),je=G({save:()=>void 0,cancel:()=>void 0,link:()=>void 0});W(()=>{if(!n.bridge)return;let e=e=>{e.type===`approval`?f(L(`Claude needs your OK for a change`,`taw-core`)):e.type===`done`&&f(e.outcome===`ok`?L(`Claude finished`,`taw-core`):e.outcome===`stopped`?L(`Stopped`,`taw-core`):L(`Claude stopped before finishing`,`taw-core`))},t=n.bridge({status(e){se.current===`connected`&&e!==`connected`&&N(Je),se.current=e,oe(e)},event(t){if(t.type===`hello`){let e=_e.current!==null&&t.session!==_e.current;(R.current||e)&&Se([]),R.current=!1,_e.current=t.session,me(null)}N(e=>qe(e,t)),e(t)}});return I.current=t,()=>{t.close(),I.current=null}},[n]),Oe(()=>{let e=le.current;e&&ue.current&&(e.scrollTop=e.scrollHeight)},[M]);let Me=()=>{let e=le.current;e&&(ue.current=e.scrollHeight-e.scrollTop-e.clientHeight<48)},Ne=Ae(()=>{let e=_?.map(!0)??null;return y(e),e},[_]);W(()=>_?.onAsset(()=>re(e=>e+1)),[_]);let Pe=Ae((e,t)=>({key:Ii(t),attribution:e?e.attribute(t):{kind:`element`,node:0,text:``},elements:[t],at:e?e.nodeOf(t):0}),[]),K=Ae(e=>{let t=V.current;t&&(e&&t.restore(),t.stop()),V.current=null,B(null)},[]),Fe=Ae((e,t)=>{K(!0),T(n=>t?n.some(t=>t.key===e.key)?n.filter(t=>t.key!==e.key):[...n,e]:[e]),D(e.key),ae(!0),f(`${L(`Selected`,`taw-core`)} ${b?xn(b.manifest,e):``}`.trim())},[b,K]),Ie=()=>{K(!0),T([]),D(null),f(L(`Selection cleared`,`taw-core`))};W(()=>{if(!x||!_||r.mode===`hidden`)return;if(v===void 0){Ne();return}let e=b,t=_.pick({hover:t=>k(t?{target:Pe(e,t),label:!0}:null),pick:(t,n)=>{let r=Pe(e,t);Fe(r,n);let i=Di(r);i&&te(i)},edit:t=>{let n=Pe(e,t);Fe(n,!1),J.current(n)},stop:()=>S(!1)});return()=>{t(),k(null)}},[x,_,r.mode,v,b,Ne,Pe,Fe]);let Le=ke(()=>b?Ci(b):null,[b]),Re=ke(()=>new Set(w.map(Di).filter(e=>e!==null)),[w]),ze=()=>{if(C===`tree`){ee(`chat`);return}Ne(),ee(`tree`)},Be=e=>{b||Ne(),ee(`tree`),te(Di(e))},Ve=(e,t)=>{Fe(e,t),e.elements[0]&&_?.reveal(e.elements[0])},q=w.find(e=>e.key===E)??w[w.length-1]??null,He=ke(()=>b&&q&&_?.edit?Sr(b,q):null,[b,q,_]),Ue=(e,t,n,r)=>{_?.patch?.(e,t,n,r);let i=Ne();i&&T(e=>e.map(e=>e.key.startsWith(`e:`)&&e.elements[0]?Pe(i,e.elements[0]):e))},Ge=e=>{if(!b||!_?.edit)return!1;let t=Sr(b,e);if(!t||!(`plan`in t))return!1;K(!0),S(!1);let n=t.plan,r=n.el.innerHTML;return V.current=_.edit(n.el,n.kind===`field`&&n.mode===`rich`?`rich`:`text`,{change:()=>B(e=>e&&V.current?{...e,after:Mi(e.plan,V.current),error:null}:e),select:()=>B(e=>e&&V.current?{...e,state:V.current.state()}:e),save:()=>je.current.save(),cancel:()=>je.current.cancel(),link:()=>je.current.link()}),B({plan:n,target:e,html:r,after:null,saving:!1,error:null,link:null,state:Ai}),ae(!0),ee(`chat`),f(n.kind===`field`&&n.mode===`rich`?`${Ni(n)}. ${L(`Esc cancels.`,`taw-core`)}`:`${Ni(n)}. ${L(`Enter saves, Esc cancels.`,`taw-core`)}`),!0},J=G(Ge);J.current=Ge;let Y=j===`connected`,Ke=async e=>{let t=I.current;if(!t||!Y||M.working||e.text.trim()===``)return;ue.current=!0;let n=await t.send(`/message`,ye?{...e,page:ye}:e);return n.ok?(ce.current=e,!0):(f(n.status===409?L(`Claude is still working on the last message`,`taw-core`):L(`Lens couldn’t send that. Is taw lens still running?`,`taw-core`)),!1)},Xe=async()=>{let e=P.trim();if(e===``)return;let t={text:e,context:Mn(b?.manifest??null,w,{url:_?.url??``,title:b?.manifest.nodes.get(0)?.label??``}),chips:Nn(b?.manifest??null,w)};F(``),await Ke(t)?ae(!1):F(e)},Ze=e=>{let t=ce.current;Ke(t&&t.text===e.text?t:{text:e.text,context:``,chips:e.chips})},Qe=async(e,t)=>{N(t=>Ye(t,e,`sending`)),(await I.current?.send(`/approve`,{id:e,allow:t}))?.ok||(N(t=>Ye(t,e,`pending`)),f(L(`Lens couldn’t send your answer. Try again.`,`taw-core`)))},$e=()=>void I.current?.send(`/stop`,{}),et=()=>void I.current?.send(`/take-over`,{}),tt=e=>{fe(!1),e&&he.current?.focus()},nt=async(e,t)=>{if(fe(!1),e===null&&M.turns.length===0)return;R.current=!0,me(e===null?null:t),ue.current=!0;let n=await I.current?.send(`/conversation`,{session:e});if(!n?.ok){R.current=!1,me(null),f(n?.status===409?L(`Stop Claude first, or wait for it to finish.`,`taw-core`):L(`Lens couldn’t open that conversation. Is taw lens still running?`,`taw-core`));return}f(e===null?L(`New chat`,`taw-core`):t)};W(()=>{if(!de)return;let e=e=>{let t=e.composedPath();!t.includes(ge.current)&&!t.includes(he.current)&&fe(!1)};return window.addEventListener(`pointerdown`,e,!0),()=>window.removeEventListener(`pointerdown`,e,!0)},[de]),W(()=>{j!==`connected`&&(fe(!1),me(null),R.current=!1)},[j]);let rt=(e,t=!1)=>void I.current?.send(`/note`,{id:e.key,name:e.name,...t?{undone:!0}:{}}),it=z?.plan.kind===`template`?Y?M.working?L(`Claude is still working on the last message. Save when it’s done.`,`taw-core`):null:L(`Template text is saved by Claude: start taw lens to send this.`,`taw-core`):null,at=async()=>{let e=z,t=V.current;if(!e||!t||e.saving)return;let r=e.plan;if(Mi(r,t)===null){K(!1);return}if(r.kind===`template`){if(it||!b){B({...e,error:it});return}B({...e,saving:!0,error:null});let n=M.turns.length;if(!await Ke(wr(b.manifest,e.target,r.source,t.text(),{url:_?.url??``,title:b.manifest.nodes.get(0)?.label??``}))){B(e=>e&&{...e,saving:!1,error:L(`Lens couldn’t send that to Claude. Is taw lens still running?`,`taw-core`)});return}t.stop(),V.current=null,B(null),ae(!1),we({index:n,el:r.el,html:e.html,file:r.source.file,label:Ni(r)});return}if(!n.fields){B({...e,error:L(`Lens can’t save fields on this page. Reload it.`,`taw-core`)});return}let i=r.mode===`rich`?Yn(Wn(t.html(),r.formats,r.el.ownerDocument).blocks):t.text();B({...e,saving:!0,error:null});let a=await n.fields.save(Cr(r.source,i,r.original));if(!a.ok){B(e=>e&&{...e,saving:!1,error:a.message});return}r.mode===`text`&&a.saved!==i&&(r.el.textContent=a.saved),t.stop(),V.current=null,B(null);let o=hn(r.source.read,r.source.path),s=++Ce.current,c=`${ve.current}-${s}`;Y&&rt({key:c,name:o}),Se(t=>[...t,{id:s,key:c,after:M.turns.length,name:o,state:`saved`,undo:Cr(r.source,a.previous,a.saved),source:r.source,el:r.el,html:e.html}]),ue.current=!0,Ue(r.source.read,r.source.path,a.saved,a.isDefault),f(`${L(`Saved to`,`taw-core`)} ${o}`)},ot=async e=>{let t=xe.find(t=>t.id===e);if(!t||!n.fields||t.state!==`saved`)return;let r=t=>Se(n=>n.map(n=>n.id===e?{...n,...t}:n));r({state:`undoing`,error:void 0});let i=await n.fields.save(t.undo);if(!i.ok){r({state:`saved`,error:i.message});return}t.el.isConnected&&(t.el.innerHTML=t.html),r({state:`undone`}),Y&&t.key&&rt({key:t.key,name:t.name},!0),Ue(t.source.read,t.source.path,i.saved,i.isDefault),f(`${L(`Undid the change to`,`taw-core`)} ${t.name}`)},st=()=>{let e=V.current;if(!e)return;e.keepSelection();let t=e.linkUrl();Ee([]),B(n=>n&&{...n,link:{text:e.linkText(),url:t,existing:t!==``}})},ct=()=>{B(e=>e&&{...e,link:null}),V.current?.focus()},lt=e=>{let t=V.current;t?.link(e),B(e=>e&&t&&{...e,link:null,after:Mi(e.plan,t),state:t.state()})},Z=e=>{window.clearTimeout(De.current),De.current=window.setTimeout(()=>{n.fields?.pages(e).then(Ee)},200)};je.current={save:()=>void at(),cancel:()=>{K(!0),f(L(`Edit cancelled`,`taw-core`))},link:st},W(()=>{if(!H)return;let e=M.turns[H.index];e&&e.outcome!==void 0&&(!(e.changed??[]).some(e=>e===H.file||e.endsWith(`/${H.file}`))&&H.el.isConnected&&(H.el.innerHTML=H.html),we(null))},[M,H]),W(()=>{(x||r.mode===`hidden`)&&K(!0)},[x,r.mode,K]),W(()=>()=>K(!0),[K]),W(()=>{if(!(!_?.onDoubleClick||x||w.length===0||z))return _.onDoubleClick(e=>{let t=w.find(t=>t.elements.some(t=>t===e||t.contains(e)));return t?J.current(t):!1})},[_,x,w,z]);let vt=e=>{e.key===`Enter`&&!e.shiftKey&&!e.isComposing&&(e.preventDefault(),Xe())};Oe(()=>{let e=be.current;e&&(e.style.height=`auto`,e.style.height=`${Math.min(e.scrollHeight,132)}px`)},[P]);let yt=z?.plan.el??H?.el??null,bt=r.mode===`hidden`?[]:[...yt?[{key:`editing`,el:yt,state:`editing`,label:z?Ni(z.plan):H?.label}]:[],...w.flatMap(e=>e.elements.filter(e=>e!==yt&&!(z&&yt?.contains(e))).map((t,n)=>({key:`${e.key}:${n}`,el:t,state:`selected`,label:n===0&&b?bn(b.manifest,e):void 0}))),...O?O.target.elements.filter(e=>!w.some(t=>t.elements.includes(e))).map((e,t)=>({key:`hover:${t}`,el:e,state:`hover`,label:O.label&&t===0&&b?bn(b.manifest,O.target):void 0})):[]];W(()=>n.push(r.mode===`docked`?r.side:null),[n,r.mode,r.side]),W(()=>n.theme(r.theme),[n,r.theme]),W(()=>n.save(r),[n,r]),W(()=>n.onResize(()=>o(n.viewport())),[n]),W(()=>{h.current===`reopen`?p.current?.focus():h.current===`hide`&&m.current?.focus(),h.current=null},[r.mode]);let St=Hr(r.sheet??Vr(a),a),Ct=e=>{g({mode:`docked`,shown:`docked`,side:e}),f(L(e===`left`?`Lens docked on the left`:`Lens docked on the right`,`taw-core`))},wt=()=>{g({mode:`sheet`,shown:`sheet`,sheet:St}),f(L(`Lens undocked into a sheet`,`taw-core`))},Tt=()=>{h.current=`reopen`,S(!1),g({mode:`hidden`})},Et=()=>{h.current=`hide`,g({mode:r.shown})},Dt=e=>{r.mode!==`sheet`||e.button!==0||e.target.closest(`button`)||(e.currentTarget.setPointerCapture?.(e.pointerId),c({pointer:e.pointerId,start:{x:e.clientX,y:e.clientY},from:St}),e.preventDefault())},Ot=e=>{if(!s||e.pointerId!==s.pointer)return;let t=Hr({x:s.from.x+e.clientX-s.start.x,y:s.from.y+e.clientY-s.start.y},a);g({sheet:t}),u(zr(t.x,a))},kt=e=>{s&&e.pointerId===s.pointer&&(c(null),u(null),l&&Ct(l))};W(()=>{if(!s)return;let e=e=>{e.key===`Escape`&&(g({sheet:s.from}),c(null),u(null))};return document.addEventListener(`keydown`,e,!0),()=>document.removeEventListener(`keydown`,e,!0)},[s,g]);let At=X(`div`,{class:`sr-only`,role:`status`,"aria-live":`polite`,children:d});if(r.mode===`hidden`)return X(`div`,{class:`lens`,children:[X(`button`,{ref:p,type:`button`,class:`reopen`,"data-side":r.side,"aria-label":L(`Show Lens`,`taw-core`),title:L(`Show Lens`,`taw-core`),onClick:Et,children:X(ut,{})}),At]});let jt=r.mode===`docked`,Nt=jt?{"--lens-top":`${a.top}px`}:{left:`${St.x}px`,top:`${St.y}px`,height:`${Br(a)}px`};return X(`div`,{class:`lens`,children:[l&&X(`div`,{class:`dock-zone`,"data-side":l,"aria-hidden":`true`,style:{"--lens-top":`${a.top}px`},children:X(`span`,{class:`dock-zone-label`,children:L(l===`left`?`Release to dock left`:`Release to dock right`,`taw-core`)})}),X(`aside`,{class:`panel`,"aria-label":L(`TAW Lens`,`taw-core`),"data-mode":r.mode,"data-side":r.side,"data-dragging":s?``:void 0,style:Nt,children:[X(`div`,{class:`header`,onPointerDown:Dt,onPointerMove:Ot,onPointerUp:kt,onPointerCancel:kt,children:[X(`div`,{class:`brand`,children:[jt?X(ut,{}):X(`span`,{class:`grip`,children:X(xt,{})}),X(`span`,{class:`brand-name`,children:L(`Lens`,`taw-core`)}),Y&&X(on,{chat:M,open:de,buttonRef:he,onToggle:()=>fe(e=>!e)}),j===`connecting`?X(`span`,{class:`status`,children:[X(Mt,{size:12}),L(`Connecting…`,`taw-core`)]}):j===`connected`?null:X(`span`,{class:`status`,children:[X(`span`,{class:`status-dot`,"aria-hidden":`true`}),L(j===`outdated`?`Restart needed`:`Not connected`,`taw-core`)]})]}),X(`div`,{class:`tools`,children:[X(`button`,{type:`button`,class:`icon-button`,"aria-label":L(`Pick an element`,`taw-core`),title:L(x?`Stop picking (Esc)`:`Pick an element`,`taw-core`),"aria-pressed":x,"aria-disabled":_?void 0:`true`,onClick:()=>_&&S(!x),children:X(dt,{})}),X(`button`,{type:`button`,class:`icon-button`,"aria-label":L(`Structure tree`,`taw-core`),title:L(`Structure tree`,`taw-core`),"aria-pressed":C===`tree`,"aria-disabled":_?void 0:`true`,onClick:()=>_&&ze(),children:X(ft,{})}),X(`button`,{type:`button`,class:`icon-button`,"aria-label":Li(r.theme),title:Li(r.theme),onClick:()=>g({theme:yi(r.theme)}),children:X(Ri,{theme:r.theme})}),jt?X(`button`,{type:`button`,class:`icon-button`,"aria-label":L(`Undock into a sheet`,`taw-core`),title:L(`Undock into a sheet`,`taw-core`),onClick:wt,children:X(pt,{})}):X(`button`,{type:`button`,class:`icon-button`,"aria-label":L(`Dock as Inspector`,`taw-core`),title:L(`Dock as Inspector`,`taw-core`),onClick:()=>Ct(Ur(St,a)),children:X(mt,{})}),X(`button`,{ref:m,type:`button`,class:`icon-button`,"aria-label":L(`Hide Lens`,`taw-core`),title:L(`Hide Lens`,`taw-core`),onClick:Tt,children:jt?X(ht,{side:r.side}):X(gt,{})})]})]}),de&&Y&&X(`div`,{ref:ge,children:X(sn,{chat:M,host:e,onNew:()=>void nt(null,``),onOpen:e=>void nt(e,M.conversations.find(t=>t.id===e)?.title??``),onClose:tt})}),X(`div`,{class:`body`,ref:le,onScroll:Me,children:C===`tree`?Le?X(ki,{root:Le,selected:Re,reveal:A,onHover:e=>k(e?{target:e,label:!1}:null),onSelect:Ve,onBack:()=>ee(`chat`)}):X(`div`,{class:`empty`,children:[X(`div`,{class:`empty-title`,children:L(`No source map on this page`,`taw-core`)}),X(`div`,{class:`empty-text`,children:L(`Lens maps what TAW renders. Reload the page; if it stays empty, check that the theme calls wp_footer().`,`taw-core`)})]}):pe===null?M.turns.length>0||M.busy&&Y||xe.length>0||M.saves.length>0?X(an,{chat:M,connection:j,notes:xe,here:ye,onAnswer:(e,t)=>void Qe(e,t),onRetry:Ze,onTakeOver:et,onUndo:e=>void ot(e)}):j===`connecting`?X(tn,{}):j===`connected`?q?X(`div`,{class:`empty`,children:X(`div`,{class:`empty-text`,children:L(`No conversation yet. Select something on the page, or tell Claude what to change.`,`taw-core`)})}):X(`div`,{class:`empty`,children:[X(dt,{size:22}),X(`div`,{class:`empty-title`,children:L(`Point at anything on the page`,`taw-core`)}),X(`div`,{class:`empty-text`,children:L(`Click an element to see what TAW knows about it, or open the structure tree. Then tell Claude what to change.`,`taw-core`)}),X(`div`,{class:`gestures`,children:[X(`span`,{children:[X(`span`,{class:`kbd`,children:L(`Click`,`taw-core`)}),` `,L(`select`,`taw-core`)]}),X(`span`,{children:[X(`span`,{class:`kbd`,children:L(`⇧ Click`,`taw-core`)}),` `,L(`add to selection`,`taw-core`)]}),X(`span`,{children:[X(`span`,{class:`kbd`,children:L(`Esc`,`taw-core`)}),` `,L(`stop picking`,`taw-core`)]})]})]}):X(en,{outdated:j===`outdated`}):X(`div`,{class:`opening-state`,role:`status`,children:[X(`div`,{class:`opening`,children:[X(Mt,{size:14}),L(`Opening “%s”…`,`taw-core`).replace(`%s`,pe)]}),X(`div`,{class:`skeleton`,"aria-hidden":`true`,children:[X(`span`,{style:{width:`60%`}}),X(`span`,{style:{width:`85%`}}),X(`span`,{style:{width:`40%`}})]})]})}),C===`chat`&&z?X(kr,{plan:z.plan,before:ji(z.plan),after:z.after,saving:z.saving,error:z.error,blocked:it,state:z.state,link:z.link,pages:Te,onSave:()=>void at(),onCancel:()=>je.current.cancel(),onFormat:e=>V.current?.format(e),onLinkOpen:st,onLinkClose:ct,onLinkUrl:Z,onLinkApply:lt}):C===`chat`&&ie&&q&&b&&_&&X(Rr,{manifest:b.manifest,target:q,editor:_.editor,edit:He,onEdit:()=>Ge(q),onClose:Ie,onShowInTree:Be}),w.length>0&&(C===`tree`||w.length>1||!ie||z)&&X(`div`,{class:`chips`,role:`list`,"aria-label":L(`Selection`,`taw-core`),children:[w.map(e=>X(`span`,{role:`listitem`,children:X(`button`,{type:`button`,class:`chip mono`,"data-primary":w.length>1&&e.key===q?.key?``:void 0,onMouseEnter:()=>k({target:e,label:!1}),onMouseLeave:()=>k(null),onFocus:()=>k({target:e,label:!1}),onBlur:()=>k(null),onClick:()=>{D(e.key),ae(!0),ee(`chat`)},children:b?xn(b.manifest,e):e.key})},e.key)),w.length===1&&X(`span`,{class:`chips-hint`,children:L(`Shift-click to add more`,`taw-core`)})]}),X(`div`,{class:`composer`,children:[X(`label`,{class:`sr-only`,for:`lens-message`,children:L(`Message`,`taw-core`)}),X(`div`,{class:`composer-box`,"data-disabled":Y&&!M.working&&pe===null?void 0:``,children:[X(`textarea`,{id:`lens-message`,ref:be,class:`composer-input`,rows:1,value:P,disabled:!Y||M.working||pe!==null,placeholder:j===`off`||j===`outdated`?L(`Start Lens to chat`,`taw-core`):M.working?L(`Claude is working…`,`taw-core`):L(`Tell Claude what to change…`,`taw-core`),onInput:e=>F(e.currentTarget.value),onKeyDown:vt}),M.working&&Y?X(`button`,{type:`button`,class:`stop`,onClick:$e,children:[X(Ft,{}),L(`Stop`,`taw-core`)]}):X(`button`,{type:`button`,class:`send`,"aria-label":L(`Send`,`taw-core`),disabled:!Y||pe!==null||P.trim()===``,onClick:()=>void Xe(),children:X(_t,{})})]})]})]}),X(ui,{rings:bt,sampler:_?.sampler??null,top:a.top,area:Gr(a,r.mode===`docked`?r.side:null),refresh:ne}),At]})}var Bi=`taw-lens-pick`,Vi=24;function Hi(e,t){try{let n=e.createElement(`canvas`);n.width=Vi,n.height=Vi;let r=n.getContext(`2d`,{willReadFrequently:!0});return r?(r.drawImage(t,0,0,Vi,Vi),{data:r.getImageData(0,0,Vi,Vi).data}):null}catch{return null}}function Ui(e,t,n,r){if(!e||t.width<=0||t.height<=0)return null;let i=Math.min(23,Math.max(0,Math.floor((n-t.left)/t.width*Vi))),a=(Math.min(23,Math.max(0,Math.floor((r-t.top)/t.height*Vi)))*Vi+i)*4,o=e.data;return{r:o[a],g:o[a+1],b:o[a+2],a:o[a+3]/255}}function Wi(e,t,n){let r=e.defaultView??window,i=new WeakMap,a=new Map,o=new Map,s,c=t=>{if(s===void 0&&(s=e.createElement(`canvas`).getContext(`2d`,{willReadFrequently:!0})),!s)return null;s.clearRect(0,0,1,1),s.fillStyle=`#000`,s.fillStyle=t,s.fillRect(0,0,1,1);let[n,r,i,a]=s.getImageData(0,0,1,1).data;return{r:n,g:r,b:i,a:a/255}};return{stackAt:(n,r)=>e.elementsFromPoint(n,r).filter(e=>e!==t&&!t.contains(e)),style:e=>r.getComputedStyle(e),color(e){return o.has(e)||o.set(e,ti(e,c)),o.get(e)??null},media(t,n,r){let a=t,o=t.currentSrc||t.src||`canvas`;if(t.tagName===`IMG`&&!t.complete)return null;let s=i.get(t);return(!s||s.key!==o)&&(s={key:o,pixels:Hi(e,a)},i.set(t,s)),Ui(s.pixels,t.getBoundingClientRect(),n,r)},backgroundImage(t,r,i,o){let s=a.get(r);if(!s){let t=new Image,i={image:t,pixels:null,ready:!1};t.crossOrigin=`anonymous`,t.onload=()=>{i.pixels=Hi(e,t),i.ready=!0,n()},t.onerror=()=>{i.ready=!0},t.src=new URL(r,e.baseURI).href,a.set(r,i),s=i}return s.ready?Ui(s.pixels,t.getBoundingClientRect(),i,o):null}}}var Gi=[`pointerdown`,`pointerup`,`mousedown`,`mouseup`,`click`,`dblclick`,`auxclick`,`touchstart`];function Ki(e,t,n){let r=e.defaultView??window,i=e=>e.composedPath().includes(t),a=n=>{let r=n.target;return!(r instanceof Element)||r===t||t.contains(r)||r===e.documentElement?null:r},o=e=>n.hover(i(e)?null:a(e)),s=e=>{e.relatedTarget||n.hover(null)},c=e=>{if(!i(e)&&(e.preventDefault(),e.stopImmediatePropagation(),e.type===`click`||e.type===`dblclick`)){let t=a(e);t&&e.type===`click`?n.pick(t,e.shiftKey):t&&n.edit?.(t)}},l=e=>{e.key===`Escape`&&(e.preventDefault(),e.stopImmediatePropagation(),n.stop())},u=e.getElementById(Bi);u||(u=e.createElement(`style`),u.id=Bi,u.textContent=`html, html * { cursor: crosshair !important; }`,e.head.appendChild(u)),r.addEventListener(`pointermove`,o,!0),e.addEventListener(`mouseout`,s,!0);for(let e of Gi)r.addEventListener(e,c,{capture:!0,passive:!1});return r.addEventListener(`keydown`,l,!0),()=>{r.removeEventListener(`pointermove`,o,!0),e.removeEventListener(`mouseout`,s,!0);for(let e of Gi)r.removeEventListener(e,c,{capture:!0});r.removeEventListener(`keydown`,l,!0),e.getElementById(Bi)?.remove(),n.hover(null)}}function qi(e,t){let n=dn(e);return n?new pr(e,n,t):null}function Ji(e,t,n){let r=e.defaultView??window,i=e=>{let r=e.target;r instanceof Element&&r!==t&&!t.contains(r)&&n(r)&&(e.preventDefault(),e.stopImmediatePropagation())};return r.addEventListener(`dblclick`,i,!0),()=>r.removeEventListener(`dblclick`,i,!0)}function Yi(e,t,n,r,i){let a=e.getElementById(cn);if(!a?.textContent)return;let o;try{o=JSON.parse(a.textContent)}catch{return}let s=/^(.+)\[(\d+)\]\.(.+)$/.exec(n),c=Array.isArray(o.nodes)?o.nodes:o.nodes&&typeof o.nodes==`object`?Object.values(o.nodes):[];for(let e of c)for(let n of e.reads??[])if(n.store===t.store&&String(n.object)===String(t.object)&&n.key===t.key){if(s){let e=typeof n.value==`string`;try{let t=e?JSON.parse(n.value):n.value,i=t.filter(e=>typeof e==`object`&&!!e);i[Number(s[2])]&&(i[Number(s[2])][s[3]]=r),n.value=e?JSON.stringify(t):t}catch{continue}}else n.value=r;i?n.default=!0:delete n.default}a.textContent=JSON.stringify(o)}var Xi={surface:`#FFFFFF`,line:`#ECEDF0`,text:`#1A1C21`,muted:`#5F6672`,mutedSoft:`#6B7280`,bubble:`#F4F5F7`,chip:`#F0F1F3`,accent:`#1A1C21`,onAccent:`#FFFFFF`,buttonLine:`#DDE0E5`,composerLine:`#E3E5E9`,amberLine:`#F0DDB6`,amberFill:`#FFFBF3`,amberDot:`#C98512`,amberText:`#6B5A35`,amberRule:`#F3E7CC`,diffRemovedFill:`#FDEEEF`,diffRemovedText:`#9F2430`,diffAddedFill:`#EAF6EF`,diffAddedText:`#1C6B3E`,menuShadow:`0 12px 32px rgba(20,24,33,.10)`},Zi={surface:`#17191E`,line:`#262A31`,text:`#E7E9ED`,muted:`#9AA1AD`,mutedSoft:`#9AA1AD`,bubble:`#22252C`,chip:`#262A31`,accent:`#E7E9ED`,onAccent:`#17191E`,buttonLine:`#3A3F4B`,composerLine:`#30343C`,amberLine:`#4A3D22`,amberFill:`#1E1A12`,amberDot:`#E0A33E`,amberText:`#C9B68E`,amberRule:`#3A3122`,diffRemovedFill:`#3A1F24`,diffRemovedText:`#F4B9BF`,diffAddedFill:`#173023`,diffAddedText:`#A6E0BE`,menuShadow:`0 12px 32px rgba(0,0,0,.40)`},Qi={dockedWidth:`400px`,sheetWidth:`400px`,sheetHeight:`600px`,sheetRadius:`12px`,sheetShadow:`0 1px 2px rgba(20,24,33,.05), 0 12px 32px rgba(20,24,33,.08)`,fontUi:`Inter, system-ui, -apple-system, "Segoe UI", sans-serif`,fontMono:`"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace`},$i=e=>`--lens-${e.replace(/[A-Z]/g,e=>`-${e.toLowerCase()}`)}`,ea=e=>Object.entries(e).map(([e,t])=>`${$i(e)}: ${t};`).join(` `);function ta(){return[`:host { ${ea(Xi)} ${ea(Qi)} color-scheme: light; }`,`@media (prefers-color-scheme: dark) { :host(:not([data-theme="light"])) { ${ea(Zi)} color-scheme: dark; } }`,`:host([data-theme="dark"]) { ${ea(Zi)} color-scheme: dark; }`].join(`
`)}var na=String.raw,ra=()=>ta()+na`
        :host {
            all: initial;
        }
        .lens,
        .lens * {
            box-sizing: border-box;
        }
        .lens {
            font-family: var(--lens-font-ui);
            font-size: 14px;
            line-height: 1.5;
            font-weight: 400;
            font-style: normal;
            letter-spacing: normal;
            text-transform: none;
            text-align: left;
            text-indent: 0;
            word-spacing: normal;
            white-space: normal;
            color: var(--lens-text);
            -webkit-font-smoothing: antialiased;
            cursor: auto;
        }
        .lens button,
        .lens input {
            font: inherit;
            color: inherit;
            margin: 0;
        }
        .lens button {
            cursor: pointer;
        }
        .lens :focus {
            outline: none;
        }
        .lens :focus-visible {
            outline: 2px solid var(--lens-accent);
            outline-offset: 2px;
        }
        .mono {
            font-family: var(--lens-font-mono);
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        /* The panel: docked (full height, square, a hairline, no shadow) or a sheet (radius, faint shadow). */
        .panel {
            position: fixed;
            z-index: 2147483647;
            display: flex;
            flex-direction: column;
            width: var(--lens-docked-width);
            background: var(--lens-surface);
            color: var(--lens-text);
        }
        .panel[data-mode='docked'] {
            top: var(--lens-top, 0px);
            bottom: 0;
        }
        .panel[data-mode='docked'][data-side='right'] {
            right: 0;
            border-left: 1px solid var(--lens-line);
        }
        .panel[data-mode='docked'][data-side='left'] {
            left: 0;
            border-right: 1px solid var(--lens-line);
        }
        .panel[data-mode='sheet'] {
            width: var(--lens-sheet-width);
            border: 1px solid var(--lens-line);
            border-radius: var(--lens-sheet-radius);
            box-shadow: var(--lens-sheet-shadow);
            overflow: hidden;
        }
        .panel[data-dragging] {
            user-select: none;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex: 0 0 auto;
            height: 52px;
            padding: 0 12px 0 16px;
            border-bottom: 1px solid var(--lens-line);
        }
        .panel[data-mode='sheet'] .header {
            height: 48px;
            padding: 0 10px 0 12px;
            cursor: grab;
            touch-action: none;
        }
        .panel[data-dragging] .header {
            cursor: grabbing;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }
        .brand-name {
            font-weight: 600;
            font-size: 14px;
        }
        .brand-host {
            font-family: var(--lens-font-mono);
            font-size: 12px;
            color: var(--lens-muted-soft);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .grip {
            display: flex;
            color: var(--lens-muted-soft);
        }
        .tools {
            display: flex;
            align-items: center;
            gap: 2px;
            flex: 0 0 auto;
        }
        .icon-button {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 0;
            padding: 0;
            background: transparent;
            color: var(--lens-muted);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .icon-button:hover,
        .icon-button[aria-pressed='true'] {
            background: var(--lens-chip);
            color: var(--lens-text);
        }
        .icon-button[aria-disabled='true'] {
            cursor: default;
            opacity: 0.45;
            background: transparent;
            color: var(--lens-muted);
        }

        .body {
            flex: 1 1 auto;
            min-height: 0;
            overflow: auto;
            display: flex;
            flex-direction: column;
        }
        .empty {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 32px;
            text-align: center;
        }
        .empty-title {
            font-size: 15px;
            font-weight: 600;
        }
        .empty-text {
            font-size: 13px;
            line-height: 1.6;
            color: var(--lens-muted);
            max-width: 280px;
        }
        .gestures {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 8px;
            font-size: 12px;
            color: var(--lens-muted);
        }
        .kbd {
            font-family: var(--lens-font-mono);
            border: 1px solid var(--lens-button-line);
            border-radius: 4px;
            padding: 1px 5px;
            color: var(--lens-text);
        }

        .composer {
            flex: 0 0 auto;
            padding: 12px 16px 16px;
        }
        .panel[data-mode='sheet'] .composer {
            padding: 10px 14px 14px;
        }
        .composer-box {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--lens-composer-line);
            background: var(--lens-surface);
            border-radius: 10px;
            padding: 6px 6px 6px 12px;
        }
        .composer-box[data-disabled] {
            background: var(--lens-bubble);
        }
        .composer-input {
            flex: 1 1 auto;
            min-width: 0;
            height: 30px;
            border: 0;
            background: transparent;
            outline: none;
            font-size: 14px;
            color: var(--lens-text);
        }
        .composer-input::placeholder {
            color: var(--lens-muted-soft);
            opacity: 1;
        }
        .composer-input:disabled {
            cursor: not-allowed;
        }
        .send {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 0;
            padding: 0;
            flex: 0 0 auto;
            background: var(--lens-accent);
            color: var(--lens-on-accent);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .send:disabled {
            background: var(--lens-composer-line);
            color: var(--lens-muted-soft);
            cursor: not-allowed;
        }

        /* Hidden: a small tab on the docked edge brings the panel back. */
        .reopen {
            position: fixed;
            z-index: 2147483647;
            bottom: 16px;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--lens-line);
            background: var(--lens-surface);
            color: var(--lens-text);
            box-shadow: var(--lens-sheet-shadow);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        .reopen[data-side='right'] {
            right: 16px;
        }
        .reopen[data-side='left'] {
            left: 16px;
        }
        .reopen:hover {
            background: var(--lens-chip);
        }

        /* Drag-to-dock: the zone that appears while a sheet is within reach of an edge. */
        .dock-zone {
            position: fixed;
            z-index: 2147483646;
            top: calc(var(--lens-top, 0px) + 12px);
            bottom: 12px;
            width: var(--lens-docked-width);
            border: 1.5px dashed var(--lens-accent);
            border-radius: 12px;
            background: color-mix(in srgb, var(--lens-accent) 4%, transparent);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding-bottom: 28px;
            pointer-events: none;
        }
        .dock-zone[data-side='left'] {
            left: 12px;
        }
        .dock-zone[data-side='right'] {
            right: 12px;
        }
        .dock-zone-label {
            background: var(--lens-surface);
            color: var(--lens-text);
            font-size: 13px;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid var(--lens-button-line);
        }

        /* Rings and label chips on the page (component 02, screen 05): click-through, under the panel. */
        .overlay {
            position: fixed;
            inset: 0;
            z-index: 2147483645;
            pointer-events: none;
        }
        .ring {
            position: fixed;
            border-radius: 4px;
        }
        .ring[data-state='selected'] {
            border: 2px solid var(--ring);
            box-shadow:
                inset 0 0 0 2px var(--halo),
                0 0 0 3px var(--halo);
        }
        .ring[data-state='editing'] {
            border: 2px dashed var(--ring);
            box-shadow:
                inset 0 0 0 2px var(--halo),
                0 0 0 3px var(--halo);
        }
        .ring[data-state='hover'] {
            border: 1px solid var(--ring);
            box-shadow:
                inset 0 0 0 1px var(--halo),
                0 0 0 1px var(--halo);
        }
        .ring-label {
            position: fixed;
            height: 24px;
            display: flex;
            align-items: center;
            padding: 0 8px;
            border-radius: 4px;
            background: var(--ring);
            color: var(--halo);
            box-shadow: 0 0 0 2px var(--halo);
            font-family: var(--lens-font-mono);
            font-size: 12px;
            line-height: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Inspect card (component 03, screen 06): in the panel, above the composer. */
        .card {
            flex: 0 0 auto;
            margin: 0 16px;
            border: 1px solid var(--lens-line);
            border-radius: 10px;
            overflow: hidden;
            max-height: 55%;
            overflow-y: auto;
            background: var(--lens-surface);
        }
        .panel[data-mode='sheet'] .card {
            margin: 0 14px;
        }
        .card-head {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 14px 4px;
            min-height: 44px;
        }
        .card-title {
            font-size: 13px;
            font-weight: 500;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .card-badge {
            flex: 0 0 auto;
            font-size: 12px;
            color: var(--lens-muted);
            background: var(--lens-chip);
            border-radius: 4px;
            padding: 1px 6px;
            white-space: nowrap;
        }
        .card-close {
            flex: 0 0 auto;
            margin-left: auto;
            width: 28px;
            height: 28px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--lens-muted-soft);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-close:hover {
            background: var(--lens-chip);
            color: var(--lens-text);
        }
        .card-location {
            padding: 0 14px 10px;
            font-size: 13px;
            line-height: 1.55;
            color: var(--lens-text);
        }
        .card-value {
            margin: 0 14px;
            padding: 10px 12px;
            background: var(--lens-bubble);
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            overflow-wrap: anywhere;
            max-height: 160px;
            overflow-y: auto;
        }
        .card-empty {
            color: var(--lens-muted);
        }
        .card-note {
            padding: 10px 14px 0;
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-muted);
        }
        .card-meta {
            padding: 10px 14px 4px;
            font-size: 12px;
            color: var(--lens-muted-soft);
            overflow-wrap: anywhere;
        }
        .card-code {
            margin: 0 14px;
            padding: 8px 10px;
            background: var(--lens-bubble);
            border-radius: 8px;
            font-size: 12px;
            line-height: 1.7;
            color: var(--lens-text);
            overflow-x: auto;
        }
        .card-code-line {
            display: flex;
            gap: 10px;
            white-space: pre;
            padding: 0 4px;
            margin: 0 -4px;
            border-radius: 3px;
        }
        .card-code-line[data-current] {
            background: var(--lens-chip);
        }
        .card-code-number {
            flex: 0 0 auto;
            min-width: 2ch;
            text-align: right;
            color: var(--lens-muted-soft);
        }
        .card-files {
            list-style: none;
            margin: 0;
            padding: 0 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
        }
        .card-files a,
        .card-link {
            color: inherit;
            text-decoration: none;
        }
        .card-files a:hover,
        .card-link:hover {
            text-decoration: underline;
        }
        .card-sources {
            list-style: none;
            margin: 8px 0 0;
            padding: 0 14px 4px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .card-sources li {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 0 8px;
            padding: 8px 10px;
            background: var(--lens-bubble);
            border-radius: 8px;
            font-size: 12px;
        }
        .card-source-name {
            font-size: 12px;
            overflow-wrap: anywhere;
        }
        .card-source-where {
            grid-column: 1;
            color: var(--lens-muted);
            overflow-wrap: anywhere;
        }
        .card-link {
            grid-column: 2;
            grid-row: 1 / span 2;
            align-self: center;
            font-size: 12px;
            font-weight: 500;
        }
        .card-actions {
            display: flex;
            gap: 8px;
            padding: 10px 14px 14px;
        }
        .card-actions:empty {
            padding: 0 0 10px;
        }
        .card-button {
            flex: 1 1 0;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            border: 1px solid var(--lens-button-line);
            background: var(--lens-surface);
            color: var(--lens-text);
            font-size: 13px;
            text-decoration: none;
            white-space: nowrap;
        }
        .card-button:hover {
            background: var(--lens-bubble);
        }
        .card-button.primary {
            border-color: var(--lens-accent);
            background: var(--lens-accent);
            color: var(--lens-on-accent);
            font-weight: 500;
        }
        .card-button[aria-disabled='true'] {
            opacity: 0.45;
            cursor: default;
        }

        /* Selection chips above the composer. */
        .chips {
            flex: 0 0 auto;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            padding: 12px 16px 0;
        }
        .chip {
            font-family: var(--lens-font-mono);
            border: 0;
            background: var(--lens-chip);
            color: var(--lens-text);
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 12px;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .chip[data-primary] {
            box-shadow: inset 0 0 0 1px var(--lens-accent);
        }
        .chips-hint {
            font-size: 12px;
            color: var(--lens-muted-soft);
        }

        /* Structure view (screen 04). */
        .structure {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 20px 20px 12px;
        }
        .structure > * {
            flex-shrink: 0;
        }
        .panel[data-mode='sheet'] .structure {
            padding: 16px 16px 10px;
        }
        .structure-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .structure-title {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--lens-muted);
        }
        .text-button {
            border: 0;
            background: transparent;
            padding: 2px 4px;
            margin: -2px -4px;
            border-radius: 4px;
            font-size: 12px;
            color: var(--lens-muted-soft);
        }
        .text-button:hover {
            color: var(--lens-text);
        }
        .filter {
            height: 34px;
            border: 1px solid var(--lens-composer-line);
            border-radius: 8px;
            padding: 0 10px;
            background: var(--lens-surface);
            font-size: 13px;
            color: var(--lens-text);
            outline: none;
            -webkit-appearance: none;
            appearance: none;
        }
        .filter::placeholder {
            color: var(--lens-muted-soft);
        }
        .filter:focus-visible {
            border-color: var(--lens-accent);
            outline: none;
        }
        .tree {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }
        .tree-row {
            display: flex;
            align-items: center;
            gap: 6px;
            height: 30px;
            padding-right: 8px;
            border-radius: 6px;
            font-size: 13px;
            cursor: default;
            min-width: 0;
        }
        .tree-row:hover {
            background: var(--lens-bubble);
        }
        .tree-row[data-selected] {
            background: var(--lens-chip);
        }
        .tree-row[data-selected] .tree-name {
            font-weight: 600;
        }
        .tree-row:focus-visible {
            outline-offset: -2px;
        }
        .tree-twisty {
            flex: 0 0 12px;
            width: 12px;
            height: 12px;
            display: flex;
            color: var(--lens-muted);
        }
        .tree-icon {
            flex: 0 0 14px;
            display: flex;
            color: var(--lens-muted);
        }
        .tree-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 0 0 auto;
            max-width: 62%;
        }
        .tree-detail {
            font-size: 11px;
            color: var(--lens-muted-soft);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 0 1000 auto;
            min-width: 24px;
        }
        .tree-hint {
            margin-left: auto;
            flex: 0 0 auto;
            font-size: 11px;
            color: var(--lens-muted-soft);
        }
        .tree-none,
        .structure-help {
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-muted-soft);
        }

        /* Header status (screen 07): not connected, connecting. */
        .status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--lens-muted);
            white-space: nowrap;
        }
        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--lens-muted-soft);
        }
        .spinner {
            flex: 0 0 auto;
        }
        @media (prefers-reduced-motion: no-preference) {
            .spinner {
                animation: lens-spin 0.9s linear infinite;
            }
        }
        @keyframes lens-spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Conversation (component 01). */
        .conversation {
            display: flex;
            flex-direction: column;
            gap: 18px;
            padding: 20px;
        }
        .panel[data-mode='sheet'] .conversation {
            padding: 16px;
        }
        .turn {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .message {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }
        .message-chips {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 6px;
            max-width: 100%;
        }
        .bubble {
            max-width: 88%;
            background: var(--lens-bubble);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            line-height: 1.55;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
        .reply {
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }
        .md > * {
            margin: 0;
        }
        .md > * + * {
            margin-top: 10px;
        }
        .md ul,
        .md ol {
            padding-left: 20px;
        }
        .md li + li {
            margin-top: 4px;
        }
        .md code,
        .step-text code,
        .notice-text code {
            font-family: var(--lens-font-mono);
            font-size: 0.88em;
        }
        .md pre {
            background: var(--lens-bubble);
            border-radius: 8px;
            padding: 10px 12px;
            overflow-x: auto;
            font-family: var(--lens-font-mono);
            font-size: 12px;
            line-height: 1.6;
            white-space: pre;
        }
        .md pre code {
            font-size: inherit;
        }
        .md a {
            color: inherit;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        .turn-note,
        .busy-line {
            font-size: 12px;
            color: var(--lens-muted-soft);
        }

        /* The activity line: quiet, one line per step kind. */
        .activity {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            font-size: 13px;
            color: var(--lens-muted);
        }
        .step-icon {
            flex: 0 0 auto;
            display: flex;
            color: var(--lens-muted);
        }
        .step-text {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .step-text code {
            color: var(--lens-text);
        }
        .step .step-details {
            margin-left: auto;
            flex: 0 0 auto;
        }
        .step-list {
            list-style: none;
            margin: 0;
            padding: 0 0 0 22px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
            color: var(--lens-muted);
        }
        .step-list li {
            display: flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
        }
        .step-list code {
            font-family: var(--lens-font-mono);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Approval card: amber means "needs your OK", and only that. */
        .approval,
        .busy {
            border: 1px solid var(--lens-amber-line);
            background: var(--lens-amber-fill);
            border-radius: 10px;
            overflow: hidden;
        }
        .approval-head,
        .busy-head {
            display: flex;
            align-items: baseline;
            gap: 8px;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 500;
            min-width: 0;
        }
        .approval-dot {
            flex: 0 0 auto;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--lens-amber-dot);
            align-self: center;
        }
        /* A long path gives way, never the title ("Edit 1 file" stays on one line). */
        .approval-title {
            flex: 0 0 auto;
            white-space: nowrap;
        }
        .approval-file {
            flex: 1 1 auto;
            font-family: var(--lens-font-mono);
            font-size: 12px;
            font-weight: 400;
            color: var(--lens-amber-text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }
        .diff,
        .approval-body {
            margin: 0;
            border-top: 1px solid var(--lens-amber-rule);
            border-bottom: 1px solid var(--lens-amber-rule);
            background: var(--lens-surface);
        }
        .diff {
            padding: 8px 0;
            font-family: var(--lens-font-mono);
            font-size: 12px;
            line-height: 1.75;
            max-height: 320px;
            overflow: auto;
        }
        .diff-line {
            padding: 0 16px;
            white-space: pre;
            color: var(--lens-muted);
        }
        .diff-line[data-kind='removed'] {
            background: var(--lens-diff-removed-fill);
            color: var(--lens-diff-removed-text);
        }
        .diff-line[data-kind='added'] {
            background: var(--lens-diff-added-fill);
            color: var(--lens-diff-added-text);
        }
        .diff-sign {
            display: inline-block;
            width: 1.4em;
        }
        .diff-more {
            font-style: italic;
        }
        .approval-body {
            padding: 10px 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .approval-note {
            font-size: 12px;
            color: var(--lens-muted);
        }
        .approval-code {
            margin: 0;
            font-family: var(--lens-font-mono);
            font-size: 12px;
            line-height: 1.6;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            max-height: 240px;
            overflow: auto;
        }
        .approval-actions,
        .busy-actions,
        .notice-actions {
            display: flex;
            gap: 8px;
            padding: 12px 16px 14px;
        }
        .approval-actions .card-button {
            height: 40px;
            font-size: 14px;
        }
        .busy-actions .card-button,
        .notice-actions .card-button {
            flex: 0 0 auto;
            padding: 0 14px;
        }
        .card-button:disabled {
            opacity: 0.45;
            cursor: default;
        }
        .approval-done {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--lens-muted);
            min-width: 0;
        }

        /* Theme busy (screen 07): warn, don't block. */
        .busy-text {
            margin: 0;
            padding: 0 16px;
            font-size: 13px;
            line-height: 1.55;
            color: var(--lens-amber-text);
        }
        .busy-compact {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            font-size: 12px;
            color: var(--lens-amber-text);
        }
        .busy-compact .text-button {
            margin-left: auto;
            flex: 0 0 auto;
        }

        /* Error (screen 07): ink, not red. */
        .notice {
            border: 1px solid var(--lens-line);
            border-radius: 10px;
            background: var(--lens-surface);
        }
        .notice-head {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px 0;
            font-size: 14px;
            font-weight: 600;
        }
        .notice-text {
            margin: 6px 0 0;
            padding: 0 16px;
            font-size: 13px;
            line-height: 1.55;
            color: var(--lens-muted);
        }
        .notice-detail {
            margin: 0 16px 14px;
            padding: 10px 12px;
            border-radius: 8px;
            background: var(--lens-bubble);
            font-family: var(--lens-font-mono);
            font-size: 11.5px;
            line-height: 1.6;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            max-height: 200px;
            overflow: auto;
        }

        /* Not connected (screen 07): the command to run. */
        .command {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            max-width: 300px;
            border: 1px solid var(--lens-composer-line);
            background: var(--lens-bubble);
            border-radius: 8px;
            padding: 6px 6px 6px 12px;
        }
        .command code {
            font-family: var(--lens-font-mono);
            font-size: 13px;
        }
        .command-copy {
            height: 28px;
            padding: 0 10px;
            border-radius: 6px;
            border: 1px solid var(--lens-button-line);
            background: var(--lens-surface);
            color: var(--lens-text);
            font-size: 12px;
        }
        .command-copy:hover {
            background: var(--lens-chip);
        }
        .empty-note {
            font-size: 12px;
            color: var(--lens-muted-soft);
        }

        /* Connecting (screen 07): skeleton lines. */
        .skeleton {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 20px;
        }
        .skeleton span {
            display: block;
            height: 10px;
            border-radius: 5px;
            background: var(--lens-chip);
        }

        /* Composer: grows with the message; Send becomes Stop while Claude works. */
        textarea.composer-input {
            resize: none;
            height: 30px;
            padding: 6px 0;
            font: inherit;
            font-size: 14px;
            line-height: 1.3;
        }
        .composer-box {
            align-items: flex-end;
        }
        .stop {
            flex: 0 0 auto;
            height: 32px;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 0 12px;
            border-radius: 6px;
            border: 1px solid var(--lens-button-line);
            background: var(--lens-surface);
            color: var(--lens-text);
            font-size: 13px;
        }
        .stop:hover {
            background: var(--lens-bubble);
        }

        /* Editing in place (component 05, screens 08–10): the card stays in the panel, the page gets the caret. */
        .card.edit {
            border-color: var(--lens-accent);
            display: flex;
            flex-direction: column;
            max-height: 75%;
        }
        .edit-status {
            margin-left: auto;
            flex: 0 0 auto;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
        }
        .edit-change {
            margin: 0 14px;
            padding: 8px 10px;
            background: var(--lens-bubble);
            border-radius: 8px;
            font-size: 12px;
            line-height: 1.6;
            overflow-wrap: anywhere;
            max-height: 140px;
            overflow-y: auto;
        }
        .edit-before {
            color: var(--lens-diff-removed-text);
            text-decoration: line-through;
        }
        .edit-after {
            color: var(--lens-diff-added-text);
        }
        .edit-hint {
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-muted-soft);
        }
        .edit-note {
            margin: 10px 14px 0;
        }
        .edit-error,
        .card-why {
            margin: 10px 14px 0;
            padding: 0;
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-text);
        }
        .edit-error {
            font-weight: 500;
        }
        .edit-actions {
            align-items: center;
            padding-top: 12px;
            /* Save and Cancel stay in view when the card scrolls (a short sheet, the link row open). */
            position: sticky;
            bottom: 0;
            background: var(--lens-surface);
        }
        .edit-actions .card-button {
            flex: 0 0 auto;
            padding: 0 16px;
            gap: 6px;
        }
        .edit-keys {
            margin-left: auto;
            font-size: 12px;
            color: var(--lens-muted-soft);
            white-space: nowrap;
        }
        .edit-toolbar {
            display: flex;
            align-items: center;
            gap: 2px;
            margin: 0 14px 10px;
            padding: 4px;
            border: 1px solid var(--lens-line);
            border-radius: 8px;
        }
        .tool {
            flex: 0 0 auto;
            width: 32px;
            height: 32px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--lens-muted);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .tool:hover {
            background: var(--lens-chip);
            color: var(--lens-text);
        }
        .tool[aria-pressed='true'] {
            background: var(--lens-accent);
            color: var(--lens-on-accent);
        }
        .tool-b {
            font-weight: 700;
            font-size: 14px;
        }
        .tool-i {
            font-style: italic;
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 15px;
        }
        .tool-rule {
            width: 1px;
            height: 18px;
            background: var(--lens-line);
            margin: 0 4px;
        }
        .tool-note {
            margin-left: auto;
            padding-right: 6px;
            font-size: 11px;
            color: var(--lens-muted-soft);
            white-space: nowrap;
        }
        .edit-link {
            margin: 0 14px 10px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .edit-link-label {
            font-size: 12px;
            color: var(--lens-muted);
        }
        .edit-link-row {
            display: flex;
            gap: 6px;
        }
        .edit-link-row .card-button {
            flex: 0 0 auto;
            height: 32px;
            padding: 0 12px;
        }
        .edit-link-input {
            flex: 1 1 auto;
            min-width: 0;
            height: 32px;
            border: 1px solid var(--lens-accent);
            border-radius: 6px;
            padding: 0 10px;
            background: var(--lens-surface);
            color: var(--lens-text);
            font-size: 13px;
            outline: none;
        }
        .edit-link-input:focus-visible {
            box-shadow:
                0 0 0 2px var(--lens-surface),
                0 0 0 4px var(--lens-accent);
        }

        /* "Saved to hero_title · Undo" (screen 09), in the conversation where it happened. */
        .saved {
            border: 1px solid var(--lens-line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
        }
        .saved-line {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .saved-text code {
            font-family: var(--lens-font-mono);
            font-size: 12px;
        }
        .saved-undo {
            margin-left: auto;
            border: 0;
            padding: 0;
            background: transparent;
            color: var(--lens-text);
            font-size: 12px;
            text-decoration: underline;
            text-underline-offset: 2px;
            display: flex;
            align-items: center;
        }
        .saved-error {
            margin-top: 6px;
            font-size: 12px;
            line-height: 1.5;
        }
        .message-from {
            font-size: 11px;
            color: var(--lens-muted-soft);
        }

        /* The conversation menu (component 06, screen 15): the header's title button and its menu. */
        .conv-title {
            display: flex;
            align-items: center;
            gap: 4px;
            min-width: 0;
            max-width: 136px;
            height: 28px;
            padding: 0 6px 0 8px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--lens-text);
            font: 500 13px var(--lens-font-ui);
            cursor: pointer;
        }
        .conv-title:hover,
        .conv-title[aria-expanded='true'] {
            background: var(--lens-chip);
        }
        .conv-title-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .conv-title svg {
            flex: 0 0 auto;
        }
        .conv-menu {
            position: absolute;
            top: 58px;
            left: 12px;
            z-index: 2;
            width: 312px;
            max-width: calc(100% - 24px);
            max-height: calc(100% - 70px);
            overflow-y: auto;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 6px;
            background: var(--lens-surface);
            border: 1px solid var(--lens-line);
            border-radius: 10px;
            box-shadow: var(--lens-menu-shadow);
        }
        .panel[data-mode='sheet'] .conv-menu {
            top: 54px;
        }
        .conv-new,
        .conv-item {
            display: flex;
            gap: 10px;
            width: 100%;
            border: 0;
            border-radius: 6px;
            padding: 8px 10px;
            background: transparent;
            color: var(--lens-text);
            text-align: left;
            font: 500 13px var(--lens-font-ui);
            cursor: pointer;
        }
        .conv-new {
            align-items: center;
        }
        .conv-item {
            align-items: flex-start;
        }
        .conv-new:hover,
        .conv-item:hover,
        .conv-item[aria-checked='true'] {
            background: var(--lens-bubble);
        }
        .conv-new[aria-disabled='true'],
        .conv-item[aria-disabled='true'] {
            color: var(--lens-muted-soft);
            background: transparent;
            cursor: default;
        }
        .conv-reason {
            padding: 2px 10px 6px;
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-text);
        }
        .conv-rule {
            height: 1px;
            margin: 4px;
            background: var(--lens-line);
        }
        .conv-label {
            padding: 6px 10px 2px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--lens-muted);
        }
        .conv-tick {
            display: flex;
            flex: 0 0 14px;
            padding-top: 2px;
        }
        .conv-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        .conv-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .conv-detail {
            font-weight: 400;
            font-size: 12px;
            color: var(--lens-muted);
        }
        .conv-empty {
            padding: 4px 10px 8px;
            font-size: 12px;
            line-height: 1.5;
            color: var(--lens-muted-soft);
        }
        .conv-host {
            padding: 6px 10px 4px;
            font-family: var(--lens-font-mono);
            font-size: 11px;
            color: var(--lens-muted-soft);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        /* A replayed conversation: the dividers, and chips for what isn't on this page. */
        .divider {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 11px;
            color: var(--lens-muted-soft);
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1 1 auto;
            height: 1px;
            background: var(--lens-line);
        }
        .chip.chip-away {
            background: transparent;
            border: 1px dashed var(--lens-button-line);
            color: var(--lens-muted-soft);
            padding: 2px 7px;
        }
        .opening {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 20px 20px 0;
            font-size: 12px;
            color: var(--lens-muted-soft);
        }

        @media (prefers-reduced-motion: no-preference) {
            .icon-button,
            .send,
            .reopen {
                transition:
                    background-color 0.12s ease,
                    color 0.12s ease;
            }
        }
    `,ia=`taw-lens`,aa=`taw-lens-push`,oa=`vscode://file/{path}:{line}`;function sa(e,t,n=oa){let r=e.defaultView??window,i=new Set,a;return{map(n=!1){return(n||a===void 0)&&(a=qi(e,t)),a},pick:n=>Ki(e,t,n),sampler:Wi(e,t,()=>i.forEach(e=>e())),onAsset(e){return i.add(e),()=>i.delete(e)},reveal(e){let t=e.getBoundingClientRect();(t.bottom<0||t.top>r.innerHeight)&&e.scrollIntoView({block:`center`,behavior:`smooth`})},editor:n,url:r.location.href,edit:(e,t,n)=>fe(e,t,n),onDoubleClick:n=>Ji(e,t,n),patch(t,n,r,i){Yi(e,t,n,r,i)}}}function ca(e,t){let n=e.defaultView??window,r=window.tawLens;return{viewport(){let t=e.getElementById(`wpadminbar`),r=t&&n.getComputedStyle(t).position===`fixed`?Math.max(0,t.getBoundingClientRect().bottom):0;return{width:e.documentElement.clientWidth||n.innerWidth,height:n.innerHeight,top:r}},push(t){let n=e.getElementById(aa);if(t===null){n?.remove();return}n||(n=e.createElement(`style`),n.id=aa,e.head.appendChild(n)),n.textContent=Wr(t)},theme(e){e===`system`?t.removeAttribute(`data-theme`):t.setAttribute(`data-theme`,e)},save:e=>_i(e),onResize(e){return n.addEventListener(`resize`,e),()=>n.removeEventListener(`resize`,e)},page:sa(e,t,window.tawLens?.editor||oa),fields:r?.field?me({field:r.field,search:r.search??``,nonce:r.nonce??``,home:n.location.origin}):void 0,bridge:r?.bridge?e=>I({bridge:r.bridge,nonce:r.nonce??``,protocol:2},e):void 0}}function la(e=document){let t=e.querySelector(ia);if(t)return t;let n=e.createElement(ia);e.documentElement.appendChild(n);let r=n.attachShadow({mode:`open`}),i=e.createElement(`style`);i.textContent=ra(),r.appendChild(i);let a=e.createElement(`div`);r.appendChild(a);let o=e.defaultView?.location.host??``;return oe(X(zi,{host:window.tawLens?.host||o,initial:gi(),env:ca(e,n)}),a),n}typeof document<`u`&&(document.readyState===`loading`?document.addEventListener(`DOMContentLoaded`,()=>la(),{once:!0}):la());
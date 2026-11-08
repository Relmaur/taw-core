var e,t,n,r,i,a,o,s,c,l,u={},d=[],f=/^m(i|n|o|s|text|space)$/,p=Array.isArray,m=d.slice,h=Object.assign;function g(e){e&&e.parentNode&&e.remove()}function _(n,r,i,a,o){var s={type:n,props:r,key:i,ref:a,__k:null,__:null,__b:0,__e:null,__c:null,constructor:void 0,__v:o||++t,__i:-1,__u:0};return!o&&e.vnode&&e.vnode(s),s}function v(e){return e.children}function y(e,t){this.props=e,this.context=t,this.__g=0}function b(e,t){if(t==null)return e.__?b(e.__,e.__i+1):null;for(var n;t<e.__k.length;t++)if((n=e.__k[t])&&n.__e)return n.__e;return typeof e.type!=`function`||e.props.__P?null:b(e)}function x(e){if((e=e.__)&&e.__c&&!e.props.__P)return e.__e=null,e.__k.some(function(t){return t&&(e.__e=t.__e)}),x(e)}function S(t){(8&t.__g||!(t.__g|=8)||!n.push(t)||i++)&&r==e.debounceRendering||((r=e.debounceRendering)||queueMicrotask)(C)}function C(){var t,r,o,s,c,l,u,d,f;try{for(r=1;n.length;)n.length>r&&n.sort(a),t=n.shift(),r=n.length,8&t.__g&&(s=void 0,c=void 0,l=(c=(o=t).__v).__e,u=[],d=[],(f=o.__P)&&((s=h({constructor:void 0},c)).__v=c.__v+1,e.vnode&&e.vnode(s),A(f,s,c,o.__n,f.namespaceURI,32&c.__u?[l]:null,u,l||b(c),32&c.__u,d),s.__v=c.__v,s.__.__k[s.__i]=s,ne(u,s,d),c.__=c.__e=null,s.__e!=l&&x(s)))}finally{n.length=i=0}}function ee(e,t,n,r,i,a,o,s,c,l,f){var p,m,h,g,_,v,y=r.__k||d,b=t.length;for(c=w(n,t,y,c,b),p=0;p<b;p++)(h=n.__k[p])!=null&&(m=~h.__i&&y[h.__i]||u,h.__i=p,v=A(e,h,m,i,a,o,s,c,l,f),g=h.__e,(m.ref!=h.ref||8&m.__u)&&(m.ref&&ie(m.ref,null,h,m),h.ref&&f.push(h.ref,h.__c||g,h)),_||=g,4&h.__u?(c=T(h,c,e,!m.__v),m.__e&&(m.__e=null)):typeof h.type==`function`&&v!==void 0?c=v:g&&(c=g.nextSibling),h.__u&=-7);return n.__e=_,c}function w(e,t,n,r,i){var a,o,s,c,l,u,d,f,m,h,g=n.length,y=g,x=0,S=!1,C=e.__k=Array(i);for(a=0;a<i;a++)(o=t[a])!=null&&typeof o!=`boolean`&&typeof o!=`function`?(typeof o!=`object`||o.constructor==String?o=C[a]=_(null,o):p(o)?o=C[a]=_(v,{children:o}):o.constructor===void 0&&o.__b?o=C[a]=_(o.type,o.props,o.key,o.ref,o.__v):C[a]=o,c=a+x,o.__=e,o.__b=e.__b+1,s=null,~(l=o.__i=E(o,n,c,y))&&(y--,(s=n[l])&&(s.__u|=2)),s&&s.__v?(o.__u|=2,l==c-1?x--:l==c+1?x++:l!=c&&(l>c?x--:x++,S=!0)):(~l||(i>g?x--:i<g&&x++),typeof o.type!=`function`&&(o.__u|=4))):C[a]=null;if(S){for(u=[],d=[],a=0;a<i;a++)if((o=C[a])&&2&o.__u){for(f=0,m=u.length;f<m;)u[h=f+m>>1]<o.__i?f=h+1:m=h;u[f]=o.__i,d[a]=f+1}for(x=u.length;a--;)d[a]&&(d[a]==x?x--:C[a].__u|=4)}if(y)for(a=0;a<g;a++)!(s=n[a])||2&s.__u||(s.__e==r&&(r=b(s)),ae(s,s));return r}function T(e,t,n,r){var i,a,o;if(typeof e.type==`function`){if(e.props.__P)return t;if(i=e.__k)for(a=0;a<i.length;a++)i[a]&&(i[a].__=e,t=T(i[a],t,n,!1));return t}for(t&&!t.parentNode&&(t=b(e))&&!t.parentNode&&(t=null),o=t;o&&o.nodeType==8;)o=o.nextSibling;for(e.__e!=o&&(!r&&n.moveBefore&&e.__e.parentNode?n.moveBefore(e.__e,t):n.insertBefore(e.__e,t||null)),t=e.__e;(t&&=t.nextSibling)&&t.nodeType==8;);return t}function E(e,t,n,r){var i,a,o,s=e.key,c=e.type,l=t[n],u=l&&!(2&l.__u);if(l===null&&s==null||u&&s==l.key&&c==l.type)return n;if(r>+!!u){for(i=n-1,a=n+1;i>=0||a<t.length;)if((l=t[o=i>=0?i--:a++])&&!(2&l.__u)&&s==l.key&&c==l.type)return o}return-1}function D(e,t,n){n??=``,t[0]==`-`?e.setProperty(t,n):e[t]=n}function O(e,t,n,r,i){var a,o;n:if(t==`style`){if(typeof n==`string`)e.style.cssText=n;else{if(typeof r==`string`&&(e.style.cssText=r=``),r)for(t in r)n&&t in n||D(e.style,t,``);if(n)for(t in n)r&&n[t]==r[t]||D(e.style,t,n[t])}}else if(t[0]==`o`&&t[1]==`n`)(e.__e||={})[t]=n,n&&r||(o=l[t]||(l[t]=k(t)),(e.__a||={})[t]=c,a=t!=(t=t.replace(s,`$1`)),(t=t.slice(2))[0]<`a`&&(t=t.toLowerCase()),n?e.addEventListener(t,o,a):e.removeEventListener(t,o,a));else{if(i==`http://www.w3.org/2000/svg`)t=t.replace(/xlink(H|:h)/,`h`).replace(/sName$/,`s`);else if(t!=`width`&&t!=`height`&&t!=`href`&&t!=`list`&&t!=`form`&&t!=`tabIndex`&&t!=`download`&&t!=`rowSpan`&&t!=`colSpan`&&t!=`role`&&t!=`popover`&&t in e)try{e[t]=n??``;break n}catch{}typeof n==`function`||(n==null||!1===n&&t[4]!=`-`?e.removeAttribute(t):e.setAttribute(t,t==`popover`&&n==1?``:n))}}function k(t){return function(n){if(this.__e){var r=this.__e[t];if(n[o]==null)n[o]=c++;else if(n[o]<this.__a[t])return;return r(e.event?e.event(n):n)}}}function A(t,n,r,i,a,o,s,c,l,u){var f,m,_,x,S,C,w,T,E,D,O,k,A,ne,ie,oe,M,se,N,P,F=n.type;if(n.constructor!==void 0)return null;if(128&r.__u&&(l=32&r.__u,f=r.__c.__z)){if(n.__u|=l,m=o=[],f.nodeType==8)for(_=1,x=f.nextSibling;x;x=x.nextSibling){if(x.nodeType==8){if(x.data.startsWith(`$s`))_++;else if(x.data.startsWith(`/$s`)&&!--_)break}o.push(x)}else o.push(f);c=o[0]}(f=e.__b)&&f(n);n:if(typeof F==`function`){S=s.length;try{if(D=n.props,O=(f=F.prototype)&&f.render,k=(f=F.contextType)&&i[f.__c],A=f?k?k.props.value:f.__:i,r.__c?2&(C=n.__c=r.__c).__g&&(C.__g|=1):(O?n.__c=C=new F(D,A):(n.__c=C=new y(D,A),C.constructor=F,C.render=j),k&&k.sub(C),C.state||(C.state={}),C.__n=i,C.__g|=8,C.__h=[],C.__k=[]),O&&(C.__s||(C.__s=C.state),F.getDerivedStateFromProps&&(C.__s==C.state&&(C.__s=h({},C.__s)),h(C.__s,F.getDerivedStateFromProps(D,C.__s)))),w=C.props,T=C.state,C.__v=n,r.__c){if(O&&!F.getDerivedStateFromProps&&D!==w&&C.componentWillReceiveProps&&C.componentWillReceiveProps(D,A),n.__v==r.__v&&!(8&C.__g)||!(4&C.__g)&&C.shouldComponentUpdate&&!1===C.shouldComponentUpdate(D,C.__s,A)){n.__v!=r.__v&&(C.props=D,C.state=C.__s,C.__g&=-9),n.__e=r.__e,n.__k=r.__k,n.__k.some(function(e){e&&(e.__=n)}),d.push.apply(C.__h,C.__k),C.__k=[],C.__h.length&&s.push(C),c=b(r);break n}C.componentWillUpdate&&C.componentWillUpdate(D,C.__s,A),O&&C.componentDidUpdate&&C.__h.push(function(){C.componentDidUpdate(w,T,E)})}else O&&!F.getDerivedStateFromProps&&C.componentWillMount&&C.componentWillMount(),O&&C.componentDidMount&&C.__h.push(C.componentDidMount);if(C.context=A,C.props=D,C.__P=t,C.__g&=-5,ne=e.__r,ie=0,O)C.state=C.__s,C.__g&=-9,ne&&ne(n),f=C.render(C.props,C.state,C.context),d.push.apply(C.__h,C.__k),C.__k=[];else do C.__g&=-9,ne&&ne(n),f=C.render(C.props,C.state,C.context),C.state=C.__s;while(8&C.__g&&++ie<25);C.state=C.__s,C.getChildContext&&(i=h({},i,C.getChildContext())),O&&r.__c&&C.getSnapshotBeforeUpdate&&(E=C.getSnapshotBeforeUpdate(w,T)),oe=f&&f.type===v&&f.key==null?f.props.children:f,D.__P&&(f=c,a=(t=D.__P).namespaceURI,l=o=null,r.props&&r.props.__P!=t&&(r.__k.some(function(e){e&&ae(e,e)}),r.__k=null),c=r.__k?b(r,0):null),c=ee(t,p(oe)?oe:[oe],n,r,i,a,o,s,c,l,u),D.__P&&(n.__e=null,c=f),n.__u&=-161,128&r.__u&&(C.__z=null),m&&m.some(g),C.__h.length&&s.push(C),1&C.__g&&(C.__g&=-4)}catch(t){if(s.length=S,n.__v=null,l||o){if(t.then){if(M=0,n.__u|=l?160:128,o){for(~(N=o.indexOf(c||void 0))||(N=o.length);(P=o[N-1])&&P.nodeType==8;)N--;for(;N<o.length;N++)if(P=o[N]){if(o[N]=null,P.nodeType==8){if(P.data.startsWith(`$s`))M++||(se=P);else if(M&&P.data.startsWith(`/$s`)&&!--M){c=P;break}}else if(!M)break}}if(!se){for(;c&&c.nodeType==8&&c.nextSibling;)c=c.nextSibling;se=c}n.__c.__z||(n.__c.__z=se),n.__e=c}else o&&o.some(g)}else n.__e=r.__e;n.__k||=r.__k||[],t.then||te(n),e.__e(t,n,r)}}else c=n.__e=re(r.__e,n,r,i,a,o,s,l,u,t);return(f=e.diffed)&&f(n),128&n.__u?void 0:c}function te(e){e&&(e.__c&&(e.__c.__g|=4),e.__k&&e.__k.some(te))}function ne(t,n,r){for(var i=0;i<r.length;)ie(r[i++],r[i++],r[i++]);e.__c&&e.__c(n,t),t.some(function(n){try{t=n.__h,n.__h=[],t.some(function(e){e.call(n)})}catch(t){e.__e(t,n.__v)}})}function re(t,n,r,i,a,o,s,c,l,d){var h,_,v,y,x,S,C,w,T,E=r.props||u,D=n.props,k=n.type;if(k==`svg`?a=`http://www.w3.org/2000/svg`:k==`math`?a=`http://www.w3.org/1998/Math/MathML`:a||=`http://www.w3.org/1999/xhtml`,o){for(h=0;h<o.length;h++)if((x=o[h])&&(k?x.localName==k:x.nodeType==3)){t=x,o[h]=null;break}}if(!t){if(w=d.ownerDocument||document,!k)return w.createTextNode(D);t=w.createElementNS(a,k,D.is&&D),c&&=(e.__m&&e.__m(n,o),!1),o=null}if(k){if(d=k==`template`?t.content:t,o=k==`textarea`&&D.defaultValue!=null?null:o&&m.call(d.childNodes),!c&&o)for(E={},h=0;h<t.attributes.length;h++)E[(x=t.attributes[h]).name]=x.value;for(h in E)x=E[h],h==`dangerouslySetInnerHTML`?v=x:h==`children`||h in D||h==`value`&&`defaultValue`in D||h==`checked`&&`defaultChecked`in D||O(t,h,null,x,a);for(h in T=1&r.__u,D)x=D[h],h==`children`?y=x:h==`dangerouslySetInnerHTML`?_=x:h==`value`?S=x:h==`checked`?C=x:c&&typeof x!=`function`||!(E[h]!==x||T&&x!=null)||O(t,h,x,E[h],a);_?(c||v&&(_.__html==v.__html||_.__html==t.innerHTML)||(t.innerHTML=_.__html),n.__k=[]):(v&&(t.textContent=``),(k==`foreignObject`||a==`http://www.w3.org/1998/Math/MathML`&&f.test(k))&&(a=`http://www.w3.org/1999/xhtml`),ee(d,p(y)?y:[y],n,r,i,a,o,s,o?o[0]:r.__k&&b(r,0),c,l),o&&o.some(g)),c&&k!=`textarea`||(h=`value`,k==`progress`&&S==null?t.removeAttribute(h):S==null||S===t[h]&&(k!=`progress`||S)||O(t,h,S,E[h],a),h=`checked`,C!=null&&C!=t[h]&&O(t,h,C,E[h],a))}else E===D||c&&t.data==D||(t.data=D);return t}function ie(t,n,r,i){try{typeof t==`function`?n?n.__x=t(n)||1:(i&&=i.__c||i.__e)&&(n=i.__x)&&(i.__x=null,typeof n==`function`?n():t(null)):t.current=n}catch(t){e.__e(t,r)}}function ae(t,n,r){var i,a;if(e.unmount&&e.unmount(t),!(i=t.ref)||i.current&&i.current!=t.__e||ie(i,null,n,t),i=t.__c){if(i.componentWillUnmount)try{i.componentWillUnmount()}catch(t){e.__e(t,n)}i.__P=i.__n=null}if(i=t.__k)for(a=0;a<i.length;a++)i[a]&&ae(i[a],n,typeof t.type!=`function`||r&&!t.props.__P);(i=t.__e)&&(r||g(i),i.__e&&(i.__e=null)),t.__e=t.__c=t.__=null}function j(e,t,n){return this.constructor(e,n)}function oe(t,n){var r,i,a,o;e.__&&e.__(t,n),n.nodeType==9&&(n=n.documentElement),i=(r=t&&32&t.__u)?null:n.__k,n.__k=_(v,{children:[t]}),a=[],o=[],A(n,n.__k,i||u,u,n.namespaceURI,i?null:n.firstChild?m.call(n.childNodes):null,a,i?i.__e:n.firstChild,r,o),ne(a,n.__k,o),n.__k.props.children=null}e={__e:function(e,t,n,r){for(var a,o,s;t=t.__;)if((a=t.__c)&&!(1&a.__g)){a.__g|=4;try{if((o=a.constructor)&&o.getDerivedStateFromError&&(a.setState(o.getDerivedStateFromError(e)),s=8&a.__g),a.componentDidCatch&&(a.componentDidCatch(e,r||{}),s=8&a.__g),s)return void(a.__g|=2)}catch(t){e=t,s=0}}throw i=0,e}},t=0,y.prototype.setState=function(e,t){var n=this.__s;n&&n!=this.state||(n=this.__s=h({},this.state)),typeof e==`function`&&(e=e(h({},n),this.props)),e&&(h(n,e),this.__v&&(t&&this.__k.push(t),S(this)))},y.prototype.forceUpdate=function(e){this.__v&&(this.__g|=4,e&&this.__h.push(e),S(this))},y.prototype.render=v,n=[],i=0,a=function(e,t){return e.__v.__b-t.__v.__b},o=Symbol(),s=/(PointerCapture)$|Capture$/i,c=0,l={};var M=3e3,se=1500,N=()=>({fetch:(...e)=>window.fetch(...e),EventSource:window.EventSource,setTimeout:(e,t)=>window.setTimeout(e,t),clearTimeout:e=>window.clearTimeout(e),visible:()=>document.visibilityState!==`hidden`}),P=e=>{let t=e;return!!t&&t.running===!0&&typeof t.url==`string`&&/^http:\/\/127\.0\.0\.1:\d+$/.test(t.url)&&typeof t.token==`string`};function F(e,t,n=N()){let r=!1,i=null,a=null,o=null,s=null,c=e=>{e!==s&&(s=e,t.status(e))},l=e=>{r||(i=n.setTimeout(()=>void u(),e))};async function u(){if(i=null,r)return;if(!n.visible()){l(M);return}let s=null;try{let t=await n.fetch(e.bridge,{credentials:`same-origin`,cache:`no-store`,headers:{"X-WP-Nonce":e.nonce}});s=t.ok?await t.json():null}catch{s=null}if(r)return;if(!P(s)){c(`off`),l(M);return}if(s.protocol!==e.protocol){c(`outdated`),l(M*4);return}c(`connecting`),o={url:s.url,token:s.token};let u=new n.EventSource(`${s.url}/events?token=${encodeURIComponent(s.token)}`);a=u,u.onmessage=e=>{let n;try{n=JSON.parse(e.data)}catch{return}n.type===`hello`&&c(`connected`),t.event(n)},u.onerror=()=>{u.close(),a===u&&(a=null,o=null),c(`off`),l(se)}}return u(),{async send(e,t){if(!o)return{ok:!1,status:0};try{let r=await n.fetch(`${o.url}${e}`,{method:`POST`,headers:{"content-type":`application/json`,"x-lens-token":o.token},body:JSON.stringify(t)});return{ok:r.ok,status:r.status}}catch{return{ok:!1,status:0}}},close(){r=!0,i!==null&&n.clearTimeout(i),a?.close(),a=null}}}var ce=`data-taw-lens-editing`,le=`taw-lens-editing`,ue=()=>/Mac|iPhone|iPad/.test(navigator.platform||navigator.userAgent);function de(e){let t=e.ownerDocument,n=t.getSelection();if(!n)return;let r=t.createTreeWalker(e,NodeFilter.SHOW_TEXT),i=null;for(let e=r.nextNode();e;e=r.nextNode())e.data.trim()!==``&&(i=e);let a=t.createRange();i?(a.setStart(i,i.data.replace(/\s+$/,``).length),a.collapse(!0)):(a.selectNodeContents(e),a.collapse(!1)),n.removeAllRanges(),n.addRange(a)}function fe(e,t){let n=t.replace(/[\r\n]+/g,` `);if(!e.execCommand?.(`insertText`,!1,n)){let t=e.getSelection(),r=t?.rangeCount?t.getRangeAt(0):null;r&&(r.deleteContents(),r.insertNode(e.createTextNode(n)),r.collapse(!1))}}function I(e,t,n){let r=e.ownerDocument,i=r.defaultView??window,a=e.innerHTML,o=e.getAttribute(`contenteditable`),s=e.hasAttribute(`style`),c=null,l=!1;if(!r.getElementById(le)){let e=r.createElement(`style`);e.id=le,e.textContent=`[${ce}] { outline: none !important; cursor: text !important; }`,r.head.appendChild(e)}if(e.setAttribute(ce,``),t===`text`)e.setAttribute(`contenteditable`,`plaintext-only`),`isContentEditable`in e&&!e.isContentEditable&&e.setAttribute(`contenteditable`,`true`);else{e.setAttribute(`contenteditable`,`true`);try{r.execCommand?.(`defaultParagraphSeparator`,!1,`p`)}catch{}}let u=e=>ue()?e.metaKey:e.ctrlKey,d=e=>{if(e.key===`Escape`){e.preventDefault(),e.stopPropagation(),n.cancel();return}if(e.key===`Enter`&&!e.isComposing&&(t===`text`||u(e))){e.preventDefault(),n.save();return}u(e)&&[`b`,`i`,`u`,`k`].includes(e.key.toLowerCase())&&(t===`text`||e.key.toLowerCase()===`u`?e.preventDefault():e.key.toLowerCase()===`k`&&(e.preventDefault(),c=_(),n.link?.()))},f=e=>{t===`text`&&(e.preventDefault(),fe(r,e.clipboardData?.getData(`text/plain`)??``))},p=e=>{t===`text`&&e.preventDefault()},m=()=>n.change(),h=t=>{t.target instanceof Node&&e.contains(t.target)&&t.preventDefault()},g=()=>{let t=r.getSelection();t?.anchorNode&&e.contains(t.anchorNode)&&n.select?.()};e.addEventListener(`keydown`,d),e.addEventListener(`paste`,f),e.addEventListener(`drop`,p),e.addEventListener(`input`,m),i.addEventListener(`click`,h,!0),r.addEventListener(`selectionchange`,g);let _=()=>{let t=r.getSelection(),n=t?.rangeCount?t.getRangeAt(0):null;return n&&e.contains(n.commonAncestorContainer)?n.cloneRange():null},v=()=>{if(e.focus(),c){let e=r.getSelection();e?.removeAllRanges(),e?.addRange(c),c=null}},y=()=>{let t=(c??_())?.startContainer??null;for(;t&&t!==e;){if(t instanceof HTMLAnchorElement)return t;t=t.parentNode}return null};return e.focus(),de(e),{el:e,text:()=>(e.textContent??``).replace(/\s+/g,` `).trim(),html:()=>e.innerHTML,restore(){e.innerHTML=a},stop:()=>{l||(l=!0,e.removeEventListener(`keydown`,d),e.removeEventListener(`paste`,f),e.removeEventListener(`drop`,p),e.removeEventListener(`input`,m),i.removeEventListener(`click`,h,!0),r.removeEventListener(`selectionchange`,g),o===null?e.removeAttribute(`contenteditable`):e.setAttribute(`contenteditable`,o),e.removeAttribute(ce),!s&&e.getAttribute(`style`)===``&&e.removeAttribute(`style`),r.querySelector(`[data-taw-lens-editing]`)||r.getElementById(le)?.remove(),r.activeElement===e&&e.blur())},focus:()=>v(),format(e){v();let t=e===`bold`?`bold`:e===`italic`?`italic`:e===`ul`?`insertUnorderedList`:e===`ol`?`insertOrderedList`:null;t&&(r.execCommand?.(t,!1),n.change())},link(e){v();let t=y();if(e===``){if(t){let e=r.createRange();e.selectNodeContents(t);let n=r.getSelection();n?.removeAllRanges(),n?.addRange(e)}r.execCommand?.(`unlink`,!1)}else if(t)t.setAttribute(`href`,e);else{let t=r.getSelection();t?.isCollapsed&&(t.modify?.(`move`,`backward`,`word`),t.modify?.(`extend`,`forward`,`word`)),r.execCommand?.(`createLink`,!1,e)}n.change()},state(){let e=e=>{try{return r.queryCommandState?.(e)??!1}catch{return!1}};return{bold:e(`bold`),italic:e(`italic`),link:y()!==null,ul:e(`insertUnorderedList`),ol:e(`insertOrderedList`)}},linkText(){let e=y();return e?(e.textContent??``).trim():(c??_())?.toString().trim()??``},linkUrl:()=>y()?.getAttribute(`href`)??``,keepSelection(){c=_()??c}}}var L=()=>window.wp?.i18n,R=(e,t)=>L()?.__(e,t)??e;function z(e,t=(e,t)=>fetch(e,t)){return{async save(n){try{let r=await t(e.field,{method:`POST`,credentials:`same-origin`,headers:{"content-type":`application/json`,"x-wp-nonce":e.nonce},body:JSON.stringify(n)}),i=await r.json().catch(()=>({}));return r.ok&&typeof i.saved==`string`?{ok:!0,saved:i.saved,previous:typeof i.previous==`string`?i.previous:null,isDefault:i.default===!0}:{ok:!1,status:r.status,error:typeof i.error==`string`?i.error:`failed`,message:typeof i.message==`string`&&i.message!==``?i.message:r.status===403?R(`WordPress refused the save. Reload the page and try again.`,`taw-core`):R(`Lens couldn’t save that. Try again.`,`taw-core`)}}catch{return{ok:!1,status:0,error:`network`,message:R(`Lens couldn’t reach the site to save. Is it still running?`,`taw-core`)}}},async pages(n){let r=n.trim();if(r===``||/^(https?:|mailto:|tel:|#)/i.test(r))return[];let i=`${e.search}${e.search.includes(`?`)?`&`:`?`}${new URLSearchParams({search:r.replace(/^\//,``),type:`post`,per_page:`8`,_fields:`title,url`})}`;try{let n=await(await t(i,{credentials:`same-origin`,headers:{"x-wp-nonce":e.nonce}})).json();return Array.isArray(n)?n.filter(e=>typeof e.url==`string`).map(t=>({title:t.title??``,url:pe(t.url,e.home)})):[]}catch{return[]}}}}function pe(e,t){try{let n=new URL(e),r=new URL(t);return n.origin===r.origin?`${n.pathname}${n.search}${n.hash}`:e}catch{return e}}var me,B,V,he,ge=Object.is,_e=0,ve=[],ye=[],H=e,be=H.__b,xe=H.__r,U=H.diffed,Se=H.__c,Ce=H.unmount,we=H.__;function Te(e,t){H.__h&&H.__h(B,e,_e||t),_e=0;var n=B.__H||(B.__H={__:[],__h:[]});return e>=n.__.length&&n.__.push({}),n.__[e]}function W(e){return _e=1,Ee(J,e)}function Ee(e,t,n){var r=Te(me++,2);if(r.t=e,!r.__c&&(r.__=[n?n(t):J(void 0,t),function(e){var t=r.__N?r.__N[0]:r.__[0],n=r.t(t,e);ge(t,n)||(r.__N=[n,r.__[1]],r.__c.setState({}))}],r.__c=B,!B.__f)){B.__f=!0;var i=B.shouldComponentUpdate;B.shouldComponentUpdate=function(e,t,n){var r=this.__H;if(!r)return!0;var a=!1,o=this.props!=e;if(r.__.some(function(e){e.__N&&(a=!0,ge(e.__[0],e.__N[0])||(o=!0))}),i){var s=i.call(this,e,t,n);return a?s||o:s}return!a||o}}return r.__}function G(e,t){var n=Te(me++,3);!H.__s&&Fe(n.__H,t)&&(n.__P=!0,n.__=e,n.u=t,B.__H.__h.push(n))}function De(e,t){var n=Te(me++,4);!H.__s&&Fe(n.__H,t)&&(n.__P=!1,n.__=e,n.u=t,B.__h.push(n))}function K(e){return _e=5,Oe(function(){return{current:e}},[])}function Oe(e,t){var n=Te(me++,7);return Fe(n.__H,t)&&(n.__=e(),n.__H=t),n.__}function ke(e,t){return _e=8,Oe(function(){return e},t)}function Ae(){var e;do{for(;e=ye.shift();)try{Ne(e)}catch(t){H.__e(t,{__:(e=e.__P)&&e.__v})}for(;e=ve.shift();){var t=e.__H;if(e.__P&&t)try{t.__h.some(Ne),t.__h.some(Pe),t.__h=[]}catch(n){t.__h=[],H.__e(n,e.__v)}}}while(ye.length)}H.__b=function(e){B=null,be&&be(e)},H.__=function(e,t){e&&t.__k&&t.__k.__m&&(e.__m=t.__k.__m),we&&we(e,t)},H.__r=function(e){xe&&xe(e),me=0;var t=(B=e.__c).__H;t&&(V==B?B.__h=[]:(t.__h.some(Ne),t.__h.some(Pe),me=0),t.__h=[],t.__.some(function(e){e.__N&&(e.__=e.__N),e.u=e.__N=void 0})),V=B},H.diffed=function(e){U&&U(e);var t=e.__c;t&&t.__H&&(t.__H.__h.length&&Me(ve.push(t)),t.__H.__.some(function(e){e.u&&(e.__H=e.u)})),V=B=null},H.__c=function(e,t){t.some(function(e){try{e.__h.some(Ne),e.__h=e.__h.filter(function(e){return!e.__||Pe(e)})}catch(n){t.some(function(e){e.__h&&=[]}),t=[],H.__e(n,e.__v)}}),Se&&Se(e,t)},H.unmount=function(e){Ce&&Ce(e);var t,n,r=e.__c;r&&r.__H&&(r.__H.__.some(function(r){try{if(r.__P&&r.__c){if(n===void 0){for(n=e.__;n&&(!n.__c||!n.__c.__P);)n=n.__;n&&=n.__c}r.__P=n,Me(ye.push(r))}else Ne(r)}catch(e){t=e}}),r.__H=void 0,t&&H.__e(t,r.__v))};var je=typeof requestAnimationFrame==`function`;function q(e){var t,n=function(){clearTimeout(r),je&&cancelAnimationFrame(t),setTimeout(e)},r=setTimeout(n,35);je&&(t=requestAnimationFrame(n))}function Me(e){e!=1&&he==H.requestAnimationFrame||((he=H.requestAnimationFrame)||q)(Ae)}function Ne(e){var t=B,n=e.__c;typeof n==`function`&&(e.__c=void 0,n()),B=t}function Pe(e){var t=B;e.__c=e.__(),B=t}function Fe(e,t){return!e||e.length!=t.length||t.some(function(t,n){return!ge(t,e[n])})}function J(e,t){return typeof t==`function`?t(e):t}function Ie(e){if(e.length===0)return``;let t=e.map(e=>e.split(`/`).slice(0,-1)),n=t[0],r=0;for(;r<n.length&&t.every(e=>e[r]===n[r]);)r++;return r>0?`${n.slice(0,r).join(`/`)}/`:``}var Le=e=>e.some(e=>e.state===`running`)?`running`:e.some(e=>e.state===`failed`)?`failed`:`done`,Re=e=>e===`read`||e===`search`||e===`plan`;function ze(e){let t=[];for(let n of e){let e=t[t.length-1];e&&Re(n.kind)&&e[0].kind===n.kind?e.push(n):t.push([n])}return t}function Be(e){let t=Le(e),n=t===`running`,r=t===`failed`,[i]=e;if(r&&e.length===1)switch(i.kind){case`read`:return{text:R(`Couldn’t read %s`,`taw-core`),code:i.target,state:t};case`edit`:return{text:R(`Didn’t edit %s`,`taw-core`),code:i.target,state:t};case`write`:return{text:R(`Didn’t write %s`,`taw-core`),code:i.target,state:t};case`run`:return{text:R(`Didn’t run %s`,`taw-core`),code:i.target,state:t}}switch(i.kind){case`read`:{let r=[...new Set(e.map(e=>e.target).filter(Boolean))];if(r.length<=1)return{text:R(n?`Reading %s`:`Read %s`,`taw-core`),code:r[0]??``,state:t};let i=Ie(r),a=String(r.length);return i?{text:R(n?`Reading %d files in %s`:`Read %d files in %s`,`taw-core`).replace(`%d`,a),code:i,state:t}:{text:R(n?`Reading %d files`:`Read %d files`,`taw-core`).replace(`%d`,a),code:``,state:t}}case`search`:return e.length===1?{text:R(n?`Searching for %s`:`Searched for %s`,`taw-core`),code:i.target,state:t}:{text:R(`Searched the theme %d times`,`taw-core`).replace(`%d`,String(e.length)),code:``,state:t};case`edit`:return{text:R(n?`Editing %s`:`Edited %s`,`taw-core`),code:i.target,state:t};case`write`:return{text:R(n?`Writing %s`:`Wrote %s`,`taw-core`),code:i.target,state:t};case`run`:return{text:R(n?`Running %s`:`Ran %s`,`taw-core`),code:i.target,state:t};case`skill`:return{text:R(`Used the %s skill`,`taw-core`),code:i.target,state:t};case`plan`:return{text:R(`Updated its plan`,`taw-core`),code:``,state:t};default:return{text:R(`Used %s`,`taw-core`),code:i.name,state:t}}}var Ve=e=>ze(e).map(Be),He=e=>e.target?`${e.name} ${e.target}`:e.name,Ue={turns:[],working:!1,busy:null,takenOver:!1,session:null},We=`connection`,Ge=(e,t,n)=>{let r=e.turns.findIndex(e=>e.turn===t);if(r<0)return e;let i=e.turns.slice();return i[r]=n(i[r]),{...e,turns:i}},Ke=e=>e.items[e.items.length-1],qe=(e,t,n)=>{for(let r of e.turns)if(r.items.some(e=>e.type===`approval`&&e.id===t))return Ge(e,r.turn,e=>({...e,items:e.items.map(e=>e.type===`approval`&&e.id===t?{...e,state:n}:e)}));return e};function Je(e,t){switch(t.type){case`hello`:return{...t.history.reduce(Je,{...Ue,session:t.session}),working:t.working,busy:t.busy,takenOver:t.takenOver,session:t.session};case`turn`:return{...e,working:!0,turns:[...e.turns,{turn:t.turn,text:t.text,chips:t.chips??[],items:[],...t.from===`edit`?{from:`edit`}:{}}]};case`text`:return Ge(e,t.turn,e=>{let n=Ke(e),r=n?.type===`text`?[...e.items.slice(0,-1),{type:`text`,text:n.text+t.delta}]:[...e.items,{type:`text`,text:t.delta}];return{...e,items:r}});case`tool`:return Ge(e,t.turn,e=>{let n={id:t.id,name:t.name,kind:t.kind,target:t.target,state:`running`},r=Ke(e),i=r?.type===`steps`?[...e.items.slice(0,-1),{type:`steps`,steps:[...r.steps,n]}]:[...e.items,{type:`steps`,steps:[n]}];return{...e,items:i}});case`tool-done`:return Ge(e,t.turn,e=>({...e,items:e.items.map(e=>e.type===`steps`?{...e,steps:e.steps.map(e=>e.id===t.id?{...e,state:t.ok?`done`:`failed`}:e)}:e)}));case`approval`:return Ge(e,t.turn,e=>({...e,items:[...e.items,{type:`approval`,id:t.id,tool:t.tool,kind:t.kind,target:t.target,input:t.input??{},state:`pending`}]}));case`approval-done`:return qe(e,t.id,t.allow?`applied`:`skipped`);case`done`:return Ge({...e,working:!1},t.turn,e=>({...e,items:e.items.map(e=>e.type===`steps`?{...e,steps:e.steps.map(e=>e.state===`running`?{...e,state:`failed`}:e)}:e.type===`approval`&&e.state!==`applied`?{...e,state:e.state===`pending`||e.state===`sending`?`skipped`:e.state}:e),outcome:t.outcome,message:t.message,detail:t.detail,changed:t.changed??[]}));case`busy`:return{...e,busy:t.busy,takenOver:t.takenOver};default:return e}}function Ye(e){let t=e.turns.find(e=>e.outcome===void 0);return!e.working||!t?{...e,working:!1}:Je(e,{type:`done`,turn:t.turn,outcome:`error`,message:We,changed:[]})}var Xe=(e,t,n)=>qe(e,t,n),Ze=400,Qe=e=>e===``?[]:e.replace(/\n$/,``).split(`
`);function $e(e,t){let n=Qe(e),r=Qe(t);if(n.length>Ze||r.length>Ze)return[...n.map(e=>({kind:`-`,text:e})),...r.map(e=>({kind:`+`,text:e}))];let i=Array.from({length:n.length+1},()=>Array(r.length+1).fill(0));for(let e=n.length-1;e>=0;e--)for(let t=r.length-1;t>=0;t--)i[e][t]=n[e]===r[t]?i[e+1][t+1]+1:Math.max(i[e+1][t],i[e][t+1]);let a=[],o=0,s=0;for(;o<n.length&&s<r.length;)n[o]===r[s]?(a.push({kind:` `,text:n[o]}),o++,s++):i[o+1][s]>=i[o][s+1]?a.push({kind:`-`,text:n[o++]}):a.push({kind:`+`,text:r[s++]});for(;o<n.length;)a.push({kind:`-`,text:n[o++]});for(;s<r.length;)a.push({kind:`+`,text:r[s++]});return a}function et(e){return e.edits?e.edits.flatMap((e,t)=>[...t>0?[{kind:` `,text:`…`}]:[],...$e(e.old,e.new)]):typeof e.content==`string`?Qe(e.content).map(e=>({kind:`+`,text:e})):[]}var tt=0;Array.isArray;function Y(t,n,r,i,a,o){n||={};var s,c,l=n;if(`ref`in l&&typeof t!=`function`)for(c in l={},n)c==`ref`?s=n[c]:l[c]=n[c];var u={type:t,props:l,key:r,ref:s,__k:null,__:null,__b:0,__e:null,__c:null,constructor:void 0,__v:--tt,__i:-1,__u:0};return(a||o)&&(u.__source=a,u.__self=o),e.vnode&&e.vnode(u),u}var X=({size:e=16,width:t=1.8,children:n})=>Y(`svg`,{width:e,height:e,viewBox:`0 0 24 24`,fill:`none`,stroke:`currentColor`,"stroke-width":t,"stroke-linecap":`round`,"stroke-linejoin":`round`,"aria-hidden":`true`,focusable:`false`,children:n}),nt=e=>Y(X,{...e,children:[Y(`circle`,{cx:`11`,cy:`11`,r:`6.5`}),Y(`path`,{d:`M16 16l4.5 4.5`})]}),rt=e=>Y(X,{...e,children:Y(`path`,{d:`M5 3l14 7-6 2-2 6z`})}),it=e=>Y(X,{...e,children:Y(`path`,{d:`M4 5h6M8 12h6M8 19h6M6 5v14M6 12h2M6 19h2`})}),at=e=>Y(X,{...e,children:[Y(`rect`,{x:`4`,y:`6`,width:`12`,height:`12`,rx:`2`}),Y(`path`,{d:`M13 3h8v8M21 3l-8 8`})]}),ot=e=>Y(X,{...e,children:[Y(`rect`,{x:`3`,y:`4`,width:`18`,height:`16`,rx:`2`}),Y(`path`,{d:`M15 4v16`})]}),st=({side:e,...t})=>Y(X,{...t,children:Y(`path`,{d:e===`right`?`M9 6l6 6-6 6`:`M15 6l-6 6 6 6`})}),ct=e=>Y(X,{...e,children:Y(`path`,{d:`M6 12h12`})}),lt=e=>Y(X,{...e,width:2,children:Y(`path`,{d:`M12 19V5M6 11l6-6 6 6`})}),ut=e=>Y(X,{...e,children:[Y(`circle`,{cx:`12`,cy:`12`,r:`8`}),Y(`path`,{d:`M12 4a8 8 0 0 1 0 16z`,fill:`currentColor`})]}),dt=e=>Y(X,{...e,children:[Y(`circle`,{cx:`12`,cy:`12`,r:`4`}),Y(`path`,{d:`M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4`})]}),ft=e=>Y(X,{...e,children:Y(`path`,{d:`M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z`})}),pt=({size:e=14})=>Y(`svg`,{width:e,height:e,viewBox:`0 0 24 24`,fill:`currentColor`,"aria-hidden":`true`,focusable:`false`,children:[Y(`circle`,{cx:`9`,cy:`6`,r:`1.6`}),Y(`circle`,{cx:`15`,cy:`6`,r:`1.6`}),Y(`circle`,{cx:`9`,cy:`12`,r:`1.6`}),Y(`circle`,{cx:`15`,cy:`12`,r:`1.6`}),Y(`circle`,{cx:`9`,cy:`18`,r:`1.6`}),Y(`circle`,{cx:`15`,cy:`18`,r:`1.6`})]}),mt=({size:e=14})=>Y(X,{size:e,width:2,children:Y(`path`,{d:`M6 6l12 12M18 6L6 18`})}),ht=({size:e=12})=>Y(X,{size:e,width:2,children:Y(`path`,{d:`M6 9l6 6 6-6`})}),gt=({size:e=12})=>Y(X,{size:e,width:2,children:Y(`path`,{d:`M9 6l6 6-6 6`})}),_t=({size:e=14})=>Y(X,{size:e,children:[Y(`rect`,{x:`5`,y:`3`,width:`14`,height:`18`,rx:`2`}),Y(`path`,{d:`M9 8h6M9 12h6`})]}),vt=({size:e=14})=>Y(X,{size:e,children:[Y(`rect`,{x:`4`,y:`4`,width:`16`,height:`16`,rx:`2`}),Y(`path`,{d:`M4 9h16`})]}),yt=({size:e=14})=>Y(X,{size:e,children:[Y(`rect`,{x:`4`,y:`4`,width:`7`,height:`7`,rx:`1`}),Y(`rect`,{x:`13`,y:`4`,width:`7`,height:`7`,rx:`1`}),Y(`rect`,{x:`4`,y:`13`,width:`16`,height:`7`,rx:`1`})]}),bt=({size:e=14})=>Y(X,{size:e,children:Y(`path`,{d:`M5 7h14M5 12h10M5 17h7`})}),xt=({size:e=14})=>Y(X,{size:e,children:Y(`path`,{d:`M6 6h12M12 6v12`})}),St=({size:e=14})=>Y(X,{size:e,width:2,children:Y(`path`,{d:`M5 12.5l4.5 4.5L19 7.5`})}),Ct=({size:e=14})=>Y(X,{size:e,width:2,children:Y(`path`,{d:`M7 7l10 10M17 7L7 17`})}),wt=({size:e=14})=>Y(`svg`,{class:`spinner`,width:e,height:e,viewBox:`0 0 24 24`,fill:`none`,"aria-hidden":`true`,focusable:`false`,children:Y(`path`,{d:`M12 3.5a8.5 8.5 0 1 1-8.5 8.5`,stroke:`currentColor`,"stroke-width":`2`,"stroke-linecap":`round`})}),Tt=({size:e=16})=>Y(X,{size:e,children:[Y(`circle`,{cx:`12`,cy:`12`,r:`8.5`}),Y(`path`,{d:`M12 7.5v5.5M12 16.4v.1`})]}),Et=({size:e=22})=>Y(X,{size:e,children:[Y(`path`,{d:`M9.5 8H8a4 4 0 0 0 0 8h1.5M14.5 8H16a4 4 0 0 1 0 8h-1.5`}),Y(`path`,{d:`M10 12h.01M14 12h.01`})]}),Dt=({size:e=10})=>Y(`svg`,{width:e,height:e,viewBox:`0 0 10 10`,fill:`currentColor`,"aria-hidden":`true`,focusable:`false`,children:Y(`rect`,{width:`10`,height:`10`,rx:`1.5`})}),Ot=e=>Y(X,{...e,width:2,children:Y(`path`,{d:`M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1`})}),kt=e=>Y(X,{...e,width:2,children:[Y(`path`,{d:`M9 6h11M9 12h11M9 18h11`}),Y(`circle`,{cx:`4.5`,cy:`6`,r:`1`}),Y(`circle`,{cx:`4.5`,cy:`12`,r:`1`}),Y(`circle`,{cx:`4.5`,cy:`18`,r:`1`})]}),At=e=>Y(X,{...e,width:2,children:Y(`path`,{d:`M10 6h10M10 12h10M10 18h10M4 5h1v4M4 15h2l-2 3h2`})});function jt(e){let t=[],n=e.replace(/\r\n?/g,`
`).split(`
`),r=[],i=()=>{r.length&&(t.push({kind:`p`,text:r.join(`
`)}),r=[])};for(let e=0;e<n.length;e++){let a=n[e];if(/^\s*```/.test(a)){i();let r=[];for(;++e<n.length&&!/^\s*```/.test(n[e]);)r.push(n[e]);t.push({kind:`code`,text:r.join(`
`)});continue}let o=/^\s*[-*+]\s+(.*)$/.exec(a),s=/^\s*\d+[.)]\s+(.*)$/.exec(a);if(o||s){i();let e=o?`ul`:`ol`,n=t[t.length-1],r=(o??s)[1];n&&n.kind===e?n.items.push(r):t.push({kind:e,items:[r]});continue}let c=/^#{1,6}\s+(.*)$/.exec(a);if(c){i(),t.push({kind:`h`,text:c[1]});continue}if(a.trim()===``){i();continue}let l=t[t.length-1];if(r.length===0&&/^\s{2,}\S/.test(a)&&l&&(l.kind===`ul`||l.kind===`ol`)){l.items[l.items.length-1]+=` ${a.trim()}`;continue}r.push(a)}return i(),t}var Mt=/^https?:\/\//i;function Nt(e){let t=[],n=/(`+)([^`]+?)\1|\*\*([^*]+)\*\*|\*([^*\s][^*]*?)\*|(?<![\w])_([^_\s][^_]*?)_(?![\w])|\[([^\]]+)\]\(([^)\s]+)\)/g,r=0,i,a=0;for(;i=n.exec(e);){if(i.index>r&&t.push(e.slice(r,i.index)),i[2]!==void 0)t.push(Y(`code`,{children:i[2]},a++));else if(i[3]!==void 0)t.push(Y(`strong`,{children:Nt(i[3])},a++));else if(i[4]!==void 0||i[5]!==void 0)t.push(Y(`em`,{children:Nt(i[4]??i[5])},a++));else{let e=i[7];t.push(Mt.test(e)?Y(`a`,{href:e,target:`_blank`,rel:`noopener noreferrer`,children:i[6]},a++):i[6])}r=i.index+i[0].length}return r<e.length&&t.push(e.slice(r)),t}var Pt=e=>e.split(`
`).flatMap((e,t)=>t===0?Nt(e):[Y(`br`,{},`br${t}`),...Nt(e)]);function Ft({source:e}){return Y(`div`,{class:`md`,children:jt(e).map((e,t)=>{switch(e.kind){case`code`:return Y(`pre`,{children:Y(`code`,{children:e.text})},t);case`h`:return Y(`p`,{children:Y(`strong`,{children:Nt(e.text)})},t);case`ul`:return Y(`ul`,{children:e.items.map((e,t)=>Y(`li`,{children:Nt(e)},t))},t);case`ol`:return Y(`ol`,{children:e.items.map((e,t)=>Y(`li`,{children:Nt(e)},t))},t);default:return Y(`p`,{children:Pt(e.text)},t)}})})}var It=`taw lens`;function Lt({text:e,code:t}){let[n,r=``]=e.split(`%s`);return Y(`span`,{class:`step-text`,children:[n,t&&Y(`code`,{children:t}),r]})}var Rt=({state:e})=>e===`running`?Y(`span`,{class:`step-icon`,children:[Y(wt,{size:13}),Y(`span`,{class:`sr-only`,children:R(`In progress`,`taw-core`)})]}):e===`failed`?Y(`span`,{class:`step-icon`,children:[Y(Ct,{size:13}),Y(`span`,{class:`sr-only`,children:R(`Didn’t finish`,`taw-core`)})]}):Y(`span`,{class:`step-icon`,children:[Y(St,{size:13}),Y(`span`,{class:`sr-only`,children:R(`Done`,`taw-core`)})]});function zt({steps:e}){let[t,n]=W(!1);return Y(`div`,{class:`activity`,children:[Ve(e).map((e,r)=>Y(`div`,{class:`step`,children:[Y(Rt,{state:e.state}),Y(Lt,{text:e.text,code:e.code}),r===0&&Y(`button`,{type:`button`,class:`text-button step-details`,"aria-expanded":t,onClick:()=>n(!t),children:R(t?`Hide details`:`Details`,`taw-core`)})]},r)),t&&Y(`ul`,{class:`step-list`,children:e.map(e=>Y(`li`,{children:[Y(Rt,{state:e.state}),Y(`code`,{children:He(e)})]},e.id))})]})}function Bt(e){switch(e.kind){case`edit`:return R(`Edit 1 file`,`taw-core`);case`write`:return R(`Write 1 file`,`taw-core`);case`run`:return R(`Run a command`,`taw-core`);default:return R(`Use %s`,`taw-core`).replace(`%s`,e.tool)}}function Vt({item:e,onAnswer:t}){let n=Bt(e),r=`approval-${e.id}`,i=e.input.file??``;if(e.state===`applied`||e.state===`skipped`)return Y(`div`,{class:`approval-done`,children:[Y(`span`,{class:`step-icon`,children:e.state===`applied`?Y(St,{size:13}):Y(Ct,{size:13})}),Y(Lt,{text:e.state===`applied`?R(`Applied: %s`,`taw-core`):R(`Skipped: %s`,`taw-core`),code:i||e.target||e.tool})]});let a=et(e.input),o=a.slice(0,200),s=e.kind===`edit`||e.kind===`write`;return Y(`div`,{class:`approval`,role:`group`,"aria-labelledby":r,children:[Y(`div`,{class:`approval-head`,children:[Y(`span`,{class:`approval-dot`,"aria-hidden":`true`}),Y(`span`,{class:`approval-title`,id:r,children:n}),i&&Y(`span`,{class:`approval-file`,children:i})]}),a.length>0?Y(`pre`,{class:`diff`,"aria-label":R(`Proposed change`,`taw-core`),children:[o.map((e,t)=>Y(`div`,{class:`diff-line`,"data-kind":e.kind===`-`?`removed`:e.kind===`+`?`added`:void 0,children:[Y(`span`,{class:`diff-sign`,"aria-hidden":`true`,children:e.kind===` `?` `:e.kind}),Y(`span`,{class:`sr-only`,children:e.kind===`-`?R(`Removed:`,`taw-core`):e.kind===`+`?R(`Added:`,`taw-core`):``}),e.text]},t)),a.length>o.length&&Y(`div`,{class:`diff-line diff-more`,children:R(`… %d more lines`,`taw-core`).replace(`%d`,String(a.length-o.length))})]}):e.input.command===void 0?e.input.json===void 0?null:Y(`div`,{class:`approval-body`,children:Y(`pre`,{class:`approval-code`,children:e.input.json})}):Y(`div`,{class:`approval-body`,children:[e.input.description&&Y(`div`,{class:`approval-note`,children:e.input.description}),Y(`pre`,{class:`approval-code`,children:e.input.command})]}),Y(`div`,{class:`approval-actions`,children:[Y(`button`,{type:`button`,class:`card-button primary`,disabled:e.state===`sending`,onClick:()=>t(e.id,!0),children:R(s?`Apply`:`Allow`,`taw-core`)}),Y(`button`,{type:`button`,class:`card-button`,disabled:e.state===`sending`,onClick:()=>t(e.id,!1),children:R(`Skip`,`taw-core`)})]})]})}function Ht({turn:e,onRetry:t,retry:n}){let[r,i]=W(!1),a=e.changed??[],o=e.message===`connection`||e.message===`agent-exit`?R(`The connection to Claude dropped.`,`taw-core`):R(`Claude reported an error.`,`taw-core`),s=[e.message!==`connection`&&e.message!==`agent-exit`?e.message:``,e.detail??``].filter(Boolean).join(`

`);return Y(`div`,{class:`notice`,role:`alert`,children:[Y(`div`,{class:`notice-head`,children:[Y(Tt,{size:15}),Y(`span`,{children:R(`Claude stopped before finishing`,`taw-core`)})]}),Y(`p`,{class:`notice-text`,children:[o,` `,a.length?R(`It had changed %s by then.`,`taw-core`).replace(`%s`,a.join(`, `)):R(`Nothing was changed after the last step you saw.`,`taw-core`)]}),Y(`div`,{class:`notice-actions`,children:[Y(`button`,{type:`button`,class:`card-button primary`,disabled:!n,onClick:()=>t(e),children:R(`Try again`,`taw-core`)}),s&&Y(`button`,{type:`button`,class:`card-button`,"aria-expanded":r,onClick:()=>i(!r),children:R(`Details`,`taw-core`)})]}),r&&s&&Y(`pre`,{class:`notice-detail`,children:s})]})}var Ut=e=>{if(!e)return``;let t=new Date(e);return Number.isNaN(t.getTime())?``:t.toLocaleTimeString([],{hour:`2-digit`,minute:`2-digit`})};function Wt({busy:e,takenOver:t,onTakeOver:n}){let[r,i]=W(`open`);if(t)return Y(`div`,{class:`busy-line`,children:R(`You took over this theme. The other Claude session is still running: you may overwrite each other’s changes.`,`taw-core`)});let a=Ut(e.since);return r===`waiting`?Y(`div`,{class:`busy busy-compact`,role:`status`,children:[Y(`span`,{class:`approval-dot`,"aria-hidden":`true`}),Y(`span`,{children:R(`Waiting for the other Claude session. Lens writes no files until it’s free.`,`taw-core`)}),Y(`button`,{type:`button`,class:`text-button`,onClick:()=>i(`confirm`),children:R(`Take over`,`taw-core`)})]}):Y(`div`,{class:`busy`,role:`status`,children:[Y(`div`,{class:`busy-head`,children:[Y(`span`,{class:`approval-dot`,"aria-hidden":`true`}),Y(`span`,{children:R(r===`confirm`?`Take over the theme?`:`Another Claude session is editing this theme`,`taw-core`)})]}),Y(`p`,{class:`busy-text`,children:r===`confirm`?R(`Lens will write files again while the other session keeps running. If you both change the same file, one of you overwrites the other.`,`taw-core`):`${a?R(`A Claude session started at %s holds the theme.`,`taw-core`).replace(`%s`,a):R(`A Claude session holds the theme.`,`taw-core`)} ${R(`You can keep picking and asking; Lens won’t write files until it’s free.`,`taw-core`)}`}),Y(`div`,{class:`busy-actions`,children:r===`confirm`?Y(v,{children:[Y(`button`,{type:`button`,class:`card-button primary`,onClick:n,children:R(`Take over`,`taw-core`)}),Y(`button`,{type:`button`,class:`card-button`,onClick:()=>i(`open`),children:R(`Cancel`,`taw-core`)})]}):Y(v,{children:[Y(`button`,{type:`button`,class:`card-button primary`,onClick:()=>i(`waiting`),children:R(`Wait for it`,`taw-core`)}),Y(`button`,{type:`button`,class:`card-button`,onClick:()=>i(`confirm`),children:R(`Take over`,`taw-core`)})]})})]})}function Gt({outdated:e}){let[t,n]=W(!1);return Y(`div`,{class:`empty`,children:[Y(Et,{}),Y(`div`,{class:`empty-title`,children:R(e?`Lens needs a restart`:`Lens isn’t connected`,`taw-core`)}),Y(`div`,{class:`empty-text`,children:R(e?`The running taw lens is from another taw/core version. Stop it (Ctrl-C) and start it again in this site’s theme folder:`:`Start it in this site’s theme folder:`,`taw-core`)}),Y(`div`,{class:`command`,children:[Y(`code`,{children:It}),Y(`button`,{type:`button`,class:`command-copy`,onClick:()=>{navigator.clipboard?.writeText(It).then(()=>{n(!0),setTimeout(()=>n(!1),1500)},()=>{})},children:R(t?`Copied`:`Copy`,`taw-core`)})]}),Y(`div`,{class:`empty-note`,children:R(`Lens reconnects on its own once it’s running.`,`taw-core`)}),Y(`div`,{class:`empty-note`,children:R(`Picking and the inspect card still work.`,`taw-core`)})]})}var Kt=()=>Y(`div`,{class:`skeleton`,"aria-hidden":`true`,children:[Y(`span`,{style:{width:`58%`}}),Y(`span`,{style:{width:`82%`}}),Y(`span`,{style:{width:`38%`}})]});function qt({turn:e,last:t,working:n,onAnswer:r,onRetry:i,retry:a}){let o=e.items[e.items.length-1],s=t&&n&&e.outcome===void 0&&(!o||o.type===`steps`&&o.steps.every(e=>e.state!==`running`));return Y(`div`,{class:`turn`,children:[Y(`div`,{class:`message`,children:[e.chips.length>0&&Y(`div`,{class:`message-chips`,children:e.chips.map((e,t)=>Y(`span`,{class:`chip`,children:e},t))}),Y(`div`,{class:`bubble`,children:e.text}),e.from===`edit`&&Y(`span`,{class:`message-from`,children:R(`Sent from an edit on the page`,`taw-core`)})]}),e.items.map((e,t)=>e.type===`text`?Y(`div`,{class:`reply`,children:Y(Ft,{source:e.text})},t):e.type===`steps`?Y(zt,{steps:e.steps},t):Y(Vt,{item:e,onAnswer:r},e.id)),s&&Y(`div`,{class:`activity`,children:Y(`div`,{class:`step`,children:[Y(Rt,{state:`running`}),Y(`span`,{class:`step-text`,children:R(`Thinking…`,`taw-core`)})]})}),e.outcome===`stopped`&&Y(`div`,{class:`turn-note`,children:R(`Stopped.`,`taw-core`)}),e.outcome===`error`&&Y(Ht,{turn:e,onRetry:i,retry:a})]})}function Jt({note:e,onUndo:t}){return Y(`div`,{class:`saved`,children:[Y(`div`,{class:`saved-line`,children:[Y(St,{}),Y(`span`,{class:`saved-text`,children:[e.state===`undone`?R(`Undid the change to`,`taw-core`):R(`Saved to`,`taw-core`),` `,Y(`code`,{class:`mono`,children:e.name})]}),e.state===`saved`&&Y(`button`,{type:`button`,class:`saved-undo`,onClick:()=>t(e.id),children:R(`Undo`,`taw-core`)}),e.state===`undoing`&&Y(`span`,{class:`saved-undo`,"aria-label":R(`Undoing…`,`taw-core`),children:Y(wt,{size:12})})]}),e.error&&Y(`div`,{class:`saved-error`,role:`alert`,children:e.error})]})}function Yt({chat:e,connection:t,notes:n=[],onAnswer:r,onRetry:i,onTakeOver:a,onUndo:o=()=>void 0}){let s=t===`connected`,c=t=>n.filter(n=>t<e.turns.length?n.after===t:n.after>=t).map(e=>Y(Jt,{note:e,onUndo:o},`note-${e.id}`));return Y(`div`,{class:`conversation`,role:`log`,"aria-label":R(`Conversation`,`taw-core`),children:[e.busy&&s&&Y(Wt,{busy:e.busy,takenOver:e.takenOver,onTakeOver:a}),e.turns.map((t,n)=>Y(v,{children:[c(n),Y(qt,{turn:t,last:n===e.turns.length-1,working:e.working,onAnswer:r,onRetry:i,retry:s&&!e.working&&n===e.turns.length-1})]},t.turn)),c(e.turns.length)]})}var Xt=`taw-lens-map`,Zt=e=>typeof e==`object`&&!!e&&!Array.isArray(e);function Qt(e){if(!e)return null;let t;try{t=JSON.parse(e)}catch{return null}if(!Zt(t)||t.version!==1)return null;let n=Array.isArray(t.nodes)?t.nodes:Zt(t.nodes)?Object.values(t.nodes):[],r=new Map;for(let e of n)Zt(e)&&typeof e.id==`number`&&r.set(e.id,{...e,reads:Array.isArray(e.reads)?e.reads:[]});return r.has(0)?{version:1,nodes:r,files:Zt(t.files)?t.files:{}}:null}var $t=e=>Qt(e.getElementById(Xt)?.textContent);function en(e,t){let n=[],r=new Set;for(let i=e.nodes.get(t);i&&!r.has(i.id);)n.push(i),r.add(i.id),i=i.parent===null?void 0:e.nodes.get(i.parent);return n}var Z=(e,t)=>e.length>t?`${e.slice(0,t-1).trimEnd()}…`:e,tn=e=>e.store===`wp`||e.store===`menu`||e.type===`wp`;function nn(e){switch(e){case`wysiwyg`:return R(`rich text`,`taw-core`);case`textarea`:return R(`long text`,`taw-core`);case`url`:case`link`:return R(`link`,`taw-core`);case`image`:return R(`image`,`taw-core`);case`repeater`:return R(`repeater`,`taw-core`);case`number`:return R(`number`,`taw-core`);case`select`:case`radio`:return R(`choice`,`taw-core`);default:return R(`text`,`taw-core`)}}function rn(e,t=e.key){return e.store===`menu`?`${R(`Menu item`,`taw-core`)} “${Z(String(e.value??``),32)}”`:tn(e)?e.key===`post_title`?R(`Post title`,`taw-core`):e.label:t}var an=e=>e.kind===`field`?rn(e.read,e.path):`“${Z(e.text,40)}”`,on=e=>e.label||e.name;function sn(e,t,n){let r=en(e,t).reverse().map(on).filter(e=>e!==``);return n&&r.push(n),r.join(` › `)}function cn(e,t){let n=en(e,t).find(e=>e.kind!==`page`)??e.nodes.get(0);return n?n.name:``}function ln(e,t){let n=t.attribution,r=cn(e,n.kind===`block`?n.node:t.at),i=n.kind===`field`||n.kind===`template`?an(n.source):n.kind===`ambiguous`?R(`ambiguous`,`taw-core`):n.kind===`mixed`?R(`mixed`,`taw-core`):n.kind===`element`?t.elements[0]?.tagName.toLowerCase()??``:``;return i?`${r} › ${i}`:r}function un(e,t){let n=t.attribution;if(n.kind===`field`||n.kind===`template`)return an(n.source);if(n.kind===`block`){let t=e.nodes.get(n.node);return t?on(t):``}return n.kind===`element`?`<${t.elements[0]?.tagName.toLowerCase()??`?`}>`:`“${Z(n.text,24)}”`}function dn(e,t,n=1){return e.replace(`{path}`,encodeURI(t)).replace(`{line}`,String(n))}var fn=400,pn=(e,t=fn)=>JSON.stringify(Z(e.replace(/\s+/g,` `).trim(),t));function mn(e){let t=[];for(let n=e;n&&t.length<5;n=n.parentElement){let e=n.tagName.toLowerCase();if(e===`html`||e===`body`)break;if(n.id&&/^[A-Za-z][\w-]*$/.test(n.id)){t.unshift(`#${n.id}`);break}let r=e+[...n.classList].filter(e=>/^[A-Za-z_-][\w-]*$/.test(e)).slice(0,2).map(e=>`.${e}`).join(``),i=n.parentElement;if(i){let e=[...i.children].filter(e=>e.tagName===n.tagName);e.length>1&&(r+=`:nth-of-type(${e.indexOf(n)+1})`)}t.unshift(r)}return t.join(` > `)}function hn(e){switch(e.store){case`post`:return`post ${e.object}`;case`term`:return`term ${e.object}`;case`user`:return`user ${e.object}`;case`option`:return`options page ${e.object} (taw fields:set options …)`;case`menu`:return`menu ${e.object}, ${e.key}`;default:return`WordPress settings`}}function gn(e,t){let n=en(e,t),r=n[0],i=n.slice().reverse().map(on).filter(Boolean).join(` › `);return r?`${i} (${r.kind===`block`?`block ${r.name}`:r.kind===`part`?`template part`:`page template`}${r.template?`, ${r.template}`:``})`:i}function _n(e,t){let{read:n}=t,r=[];if(tn(n))r.push(`WordPress data: ${n.label}${n.key===`post_title`?` of post ${n.object}`:``}`),n.store===`menu`&&r.push(`Menu item ${n.key} in menu ${n.object}${n.target?`, linking to post ${n.target}`:``}`);else{let e=/^(.+?)\[(\d+)\]\.(.+)$/.exec(t.path);r.push(e?`Field ${e[1]}, row ${Number(e[2])+1}, sub-field ${e[3]} (${t.label}; repeater)`:`Field ${t.path} (${t.label}, ${n.type})`),r.push(`Stored on: ${hn(n)}`)}let i=typeof n.value==`string`?n.value:JSON.stringify(n.value??``);return r.push(`Value: ${pn(i)}${t.part?` (the element shows part of it)`:``}`),n.default&&r.push(`Nothing is stored yet: the page shows the field’s default.`),r.push(`Rendered in: ${gn(e,t.node)}`),n.admin&&r.push(`By hand: ${n.admin}`),r}function vn(e,t){let n=t.how===`i18n`?`, translatable (inside a gettext call such as esc_html_e)`:t.how===`literal`?`, a PHP string`:``;return[`Template text ${pn(t.text,200)}`,`File: ${t.file} line ${t.line}${n}`,`Rendered in: ${gn(e,t.node)}`]}var yn=(e,t)=>t.kind===`field`?_n(e,t):vn(e,t);function bn(e,t){let n=t.attribution;switch(n.kind){case`field`:case`template`:return yn(e,n.source);case`ambiguous`:return[`Ambiguous: the text ${pn(n.text,200)} comes from ${n.sources.length} places. Don't pick one: ask which.`,...n.sources.flatMap((t,n)=>[`Candidate ${n+1}:`,...yn(e,t).map(e=>`  ${e}`)])];case`mixed`:return[`Mixed: template text around values. The text: ${pn(n.text,200)}`,...n.sources.flatMap((t,n)=>[`Piece ${n+1}:`,...yn(e,t).map(e=>`  ${e}`)])];case`block`:{let t=e.nodes.get(n.node);if(!t)return[`A block`];let r=[t.kind===`block`?`Block ${t.name} (${on(t)})`:t.kind===`part`?`Template part ${t.name}`:`The page (${t.template??t.name})`,`Rendered in: ${gn(e,n.node)}`];t.files?.length?r.push(`Files: ${t.files.join(`, `)}`):t.template&&r.push(`File: ${t.template}`);let i=t.reads.filter(e=>!tn(e)).map(e=>`${e.key} (${e.label})`);return i.length&&r.push(`Its fields: ${i.join(`, `)}`),r}default:return[`An element whose text TAW didn't render from a field or template text${n.text?`: ${pn(n.text,200)}`:``}. JavaScript may add it, or it is computed.`,`Rendered in: ${gn(e,n.node)}`]}}function xn(e,t,n){if(!e||t.length===0)return``;let r=t.map((t,n)=>{let r=t.elements[0],i=bn(e,t);return r&&i.push(`Element: <${r.tagName.toLowerCase()}> at \`${mn(r)}\``),[`${n+1}. ${i[0]}`,...i.slice(1).map(e=>`   ${e}`)].join(`
`)});return[`<lens-selections page=${JSON.stringify(n.title)} url=${JSON.stringify(n.url)}>`,`What the developer selected on the page, from TAW’s source map:`,...r,`</lens-selections>`].join(`
`)}function Sn(e,t){return e?t.map(t=>{let n=t.attribution,r=n.kind===`block`?e.nodes.get(n.node):void 0,i=n.kind===`field`?tn(n.source.read)?R(`WordPress`,`taw-core`):R(`field`,`taw-core`):n.kind===`template`?R(`template text`,`taw-core`):n.kind===`block`?r?.kind===`page`?R(`page`,`taw-core`):r?.kind===`part`?R(`template part`,`taw-core`):R(`block`,`taw-core`):n.kind===`ambiguous`?R(`ambiguous`,`taw-core`):n.kind===`mixed`?R(`mixed`,`taw-core`):R(`element`,`taw-core`);return`${n.kind===`element`?ln(e,t):un(e,t)} · ${i}`}):[]}var Cn={STRONG:`strong`,B:`strong`,EM:`em`,I:`em`},wn=[`href`,`target`,`rel`],Tn=new Set([`SPAN`,`FONT`]),En=new Set([`P`,`DIV`]),Dn=e=>e.replace(/&/g,`&amp;`).replace(/</g,`&lt;`).replace(/>/g,`&gt;`),On=e=>Dn(e).replace(/"/g,`&quot;`),kn=e=>e.replace(/[\t\n\r\f ]+/g,` `);function An(e){let t=e;for(let e=``;e!==t;)e=t,t=t.replace(/<(strong|em)>\s*<\/\1>/g,``).replace(/<\/(strong|em)><\1>/g,``);return t.replace(/ *<br> */g,`<br>`).replace(/^(?: |<br>)+|(?: |<br>)+$/g,``).trim()}var jn=class{formats;lossy=!1;constructor(e){this.formats=e}inline(e){if(e.nodeType===Node.TEXT_NODE)return Dn(kn(e.data));if(e.nodeType!==Node.ELEMENT_NODE)return``;let t=e,n=()=>[...t.childNodes].map(e=>this.inline(e)).join(``);if(t.tagName===`BR`)return`<br>`;let r=Cn[t.tagName];return r?((!this.formats.has(r===`strong`?`bold`:`italic`)||t.attributes.length>0)&&(this.lossy=!0),this.formats.has(r===`strong`?`bold`:`italic`)?`<${r}>${n()}</${r}>`:n()):t.tagName===`A`&&t.hasAttribute(`href`)?((!this.formats.has(`link`)||[...t.attributes].some(e=>!wn.includes(e.name)))&&(this.lossy=!0),this.formats.has(`link`)?`<a${wn.filter(e=>t.hasAttribute(e)).map(e=>` ${e}="${On(t.getAttribute(e)??``)}"`).join(``)}>${n()}</a>`:n()):((!Tn.has(t.tagName)||t.attributes.length>0)&&(this.lossy=!0),n())}blocks(e){let t=[],n=``,r=()=>{let e=An(n);e!==``&&t.push({type:`p`,html:e}),n=``};for(let i of[...e.childNodes]){let e=i.nodeType===Node.ELEMENT_NODE?i:null;if(e&&En.has(e.tagName)){r(),e.attributes.length>0&&(this.lossy=!0),t.push(...this.blocks(e));continue}if(e&&(e.tagName===`UL`||e.tagName===`OL`)){r(),(!this.formats.has(`lists`)||e.attributes.length>0)&&(this.lossy=!0);let n=[...e.children].filter(e=>e.tagName===`LI`).map(e=>An([...e.childNodes].map(e=>this.inline(e)).join(``))).filter(e=>e!==``);e.children.length!==n.length&&(this.lossy=!0),this.formats.has(`lists`)?n.length>0&&t.push({type:e.tagName===`UL`?`ul`:`ol`,items:n}):n.forEach(e=>t.push({type:`p`,html:e}));continue}n+=this.inline(i)}return r(),t}},Mn=(e,t)=>{let n=t.createElement(`template`);return n.innerHTML=e,n.content};function Nn(e,t,n=document){let r=new jn(new Set(t));return{blocks:r.blocks(Mn(e,n)),lossy:r.lossy}}function Pn(e,t,n=document){return Nn(/<p[\s>]/i.test(e)?e:e.replace(/\r\n?/g,`
`).replace(/\s*(<(ul|ol)[\s>][\s\S]*?<\/\2>)\s*/gi,`

$1

`).split(/\n\s*\n/).map(e=>e.trim()).filter(e=>e!==``).map(e=>/^<(ul|ol)[\s>]/i.test(e)?e.replace(/\s*(<\/?(?:ul|ol|li)(?:\s[^>]*)?>)\s*/gi,`$1`):`<p>${e.replace(/\s*\n\s*/g,`<br>`)}</p>`).join(``),t,n)}var Fn=(e,t)=>JSON.stringify(e)===JSON.stringify(t);function In(e){return e.map(e=>e.type===`p`?`<p>${e.html}</p>`:`<${e.type}>\n${e.items.map(e=>`<li>${e}</li>`).join(`
`)}\n</${e.type}>`).join(`

`)}var Ln=()=>window.wp?.autop?.removep;function Rn(e,t=Ln()??null){if(t)return t(In(e)).trim();let n=``;return e.forEach((t,r)=>{r>0&&(n+=t.type===`p`&&e[r-1].type===`p`?`

`:`
`),n+=t.type===`p`?t.html.replace(/<br>/g,`
`):`<${t.type}>\n${t.items.map(e=>` \t<li>${e.replace(/<br>/g,`
`)}</li>`).join(`
`)}\n</${t.type}>`}),n}function zn(e,t=document){return e.map(e=>(Mn((e.type===`p`?e.html:e.items.join(` · `)).replace(/<br>/g,` `),t).textContent??``).trim()).join(` ¶ `)}var Bn=new Set([`checkbox`,`toggle`,`color`,`hidden`,`image`,`gallery`,`file`,`post_select`]),Vn=new Set([`SCRIPT`,`STYLE`,`NOSCRIPT`,`TEMPLATE`,`HEAD`,`TITLE`]),Hn=new Set([`HTML`,`HEAD`,`BODY`]),Un=4e3,Q=e=>e.replace(/\s+/g,` `).trim();function Wn(e,t=document){let n=t.createElement(`template`);return n.innerHTML=e,Q(n.content.textContent??``)}var Gn=(e,t)=>e===`wysiwyg`||/<[a-z][^>]*>/i.test(t);function Kn(e){let t=e;if(typeof t==`string`&&/^\s*\[/.test(t))try{t=JSON.parse(t)}catch{return null}return Array.isArray(t)?t.filter(e=>typeof e==`object`&&!!e):null}var qn=new WeakMap;function Jn(e,t=document){let n=qn.get(e);if(n)return n;let r=[];for(let n of e.reads){if(Bn.has(n.type))continue;let i=n.type===`repeater`||n.subs?Kn(n.value):null;if(i){i.forEach((i,a)=>{for(let[o,s]of Object.entries(i)){if(typeof s!=`string`&&typeof s!=`number`)continue;let i=String(s),c=n.subs?.[o]?.type??`text`;if(!i.trim()||Bn.has(c))continue;let l=n.subs?.[o]?.label??o,u=Gn(c,i);r.push({source:{kind:`field`,node:e.id,read:n,path:`${n.key}[${a}].${o}`,label:`${n.label} › ${l} (row ${a+1})`},text:u?Wn(i,t):Q(i),rich:u})}});continue}if(typeof n.value!=`string`&&typeof n.value!=`number`)continue;let a=String(n.value);if(!a.trim())continue;let o=Gn(n.type,a);r.push({source:{kind:`field`,node:e.id,read:n,path:n.key,label:n.label},text:o?Wn(a,t):Q(a),rich:o})}return qn.set(e,r),r}function Yn(e,t){let n=t.template;return n?(e.files[n]?.texts??[]).map(e=>({kind:`template`,node:t.id,file:n,line:e.line,text:Q(e.text),how:e.kind})):[]}function Xn(e){return e.kind===`field`?`f:${e.read.store}:${e.read.object}:${e.path}`:`t:${e.file}:${e.line}:${e.text}`}var Zn=e=>`f:${e.store}:${e.object}:${e.key}`;function Qn(e){let t=new Map;for(let n of e){let e=Xn(n);t.has(e)||t.set(e,n)}return[...t.values()]}function $n(e){let t=new Set(e.flatMap(e=>e.kind===`field`&&e.read.store===`menu`&&e.read.target?[e.read.target]:[]));return t.size===0?e:e.filter(e=>!(e.kind===`field`&&e.read.store===`post`&&e.read.key===`post_title`&&t.has(Number(e.read.object))))}function er(e,t,n,r=document){let i=en(e,t);for(let t of i){let i=[],a=[];for(let e of Jn(t,r))e.text===n?i.push(e.source):e.rich&&n.length>=2&&e.text.includes(n)&&a.push({...e.source,part:!0});for(let r of Yn(e,t))r.text===n&&i.push(r);let o=Qn($n(i.length>0?i:a));if(o.length===1){let[e]=o;return e.kind===`field`?{kind:`field`,node:t.id,text:n,source:e}:{kind:`template`,node:t.id,text:n,source:e}}if(o.length>1)return{kind:`ambiguous`,node:t.id,text:n,sources:o}}for(let t of i){let i=[];for(let e of Jn(t,r))e.text.length>=2&&n.includes(e.text)&&i.push(e.source);for(let r of Yn(e,t))r.text.length>=2&&n.includes(r.text)&&i.push(r);if(i.length>0)return{kind:`mixed`,node:t.id,text:n,sources:Qn(i)}}return{kind:`element`,node:t,text:n}}function tr(e){let t=``,n=e.ownerDocument.createTreeWalker(e,NodeFilter.SHOW_TEXT);for(let e=n.nextNode();e;e=n.nextNode())e.parentElement?.closest(`script,style,noscript,template`)||(t+=e.data);return Q(t)}var nr=class{manifest;doc;nodeByElement=new Map;roots=new Map;bySource=new Map;sourcesByKey=new Map;attributions=new Map;order=new Map;constructor(e,t,n){this.doc=e,this.manifest=t,this.scan(n??null)}nodeOf(e){return this.nodeByElement.get(e)??0}rootsOf(e){return this.roots.get(e)??[]}elementsOf(e){return this.bySource.get(e)??[]}sources(){return this.sourcesByKey}position(e){return this.order.get(e)??2**53-1}attribute(e){let t=this.attributions.get(e);if(t)return t;let n=this.compute(e);return this.attributions.set(e,n),n}isRoot(e){let t=this.nodeOf(e),n=e.parentElement;return t!==0&&(!n||Hn.has(n.tagName)||this.nodeOf(n)!==t)}compute(e){if(Hn.has(e.tagName))return{kind:`block`,node:0};let t=this.nodeOf(e);if(e.tagName===`IMG`){let n=this.imageField(e,t);if(n)return{kind:`field`,node:n.node,text:``,source:n}}let n=tr(e),r=n&&n.length<=Un?er(this.manifest,t,n,this.doc):null;if(r&&(r.kind===`field`||r.kind===`template`||r.kind===`ambiguous`))return r;if(this.isRoot(e))return{kind:`block`,node:t};let i=e.tagName===`A`?this.linkField(e,t):null;return i?{kind:`field`,node:i.node,text:n,source:i}:r?.kind===`mixed`?r:{kind:`element`,node:t,text:n}}imageField(e,t){let n=`${e.getAttribute(`src`)??``} ${e.getAttribute(`srcset`)??``}`;for(let e of en(this.manifest,t)){let t=e.reads.find(e=>e.type===`image`&&e.stem&&n.includes(`/${e.stem}`));if(t)return{kind:`field`,node:e.id,read:t,path:t.key,label:t.label}}return null}linkField(e,t){let n=e.getAttribute(`href`);if(!n)return null;for(let e of en(this.manifest,t)){let t=e.reads.find(e=>(e.type===`url`||e.type===`link`)&&typeof e.value==`string`&&e.value===n);if(t)return{kind:`field`,node:e.id,read:t,path:t.key,label:t.label}}return null}add(e,t){let n=this.bySource.get(e);n?n.includes(t)||n.push(t):this.bySource.set(e,[t])}scan(e){let t=[0],n=new Set,r=this.doc.createTreeWalker(this.doc,NodeFilter.SHOW_COMMENT|NodeFilter.SHOW_ELEMENT|NodeFilter.SHOW_TEXT),i=0;for(let a=r.nextNode();a;a=r.nextNode()){if(a.nodeType===Node.COMMENT_NODE){let e=/^(\/)?taw-lens:(\d+)$/.exec(a.data);if(e){if(e[1]){let n=t.lastIndexOf(Number(e[2]));n>0&&(t.length=n)}else t.push(Number(e[2]))}continue}if(a.nodeType===Node.ELEMENT_NODE){let n=a;if(e&&(n===e||e.contains(n)))continue;this.nodeByElement.set(n,t[t.length-1]),this.order.set(n,i++);continue}let r=a.parentElement;r&&Q(a.data)&&!Vn.has(r.tagName)&&!r.closest(`script,style,noscript,template,head`)&&!(e&&e.contains(r))&&n.add(r)}for(let[e,t]of this.nodeByElement)if(t!==0&&this.isRoot(e)&&!Vn.has(e.tagName)&&e.tagName!==`LINK`&&e.tagName!==`META`){let n=this.roots.get(t)??[];n.push(e),this.roots.set(t,n)}for(let[e,t]of this.nodeByElement)if(t===0&&!Hn.has(e.tagName)&&!Vn.has(e.tagName)&&e.parentElement){let t=e.parentElement;if(Hn.has(t.tagName)||this.nodeOf(t)!==0){let t=this.roots.get(0)??[];t.push(e),this.roots.set(0,t)}}let a=[...new Set([...n,...this.doc.querySelectorAll(`img, a[href]`)])].filter(t=>!(e&&e.contains(t)));for(let e of a){let t=this.attribute(e),n=t.kind===`field`||t.kind===`template`?[t.source]:t.kind===`ambiguous`?t.sources:[];for(let t of n){let n=Xn(t);this.add(n,e),this.sourcesByKey.has(n)||this.sourcesByKey.set(n,t),t.kind===`field`&&this.add(Zn(t.read),e)}}}},rr=/^(.+)\[(\d+)\]\.(.+)$/,ir=[`bold`,`italic`,`link`,`lists`];function ar(e,t){let n=rr.exec(t),r=n?e.subs?.[n[3]]:e,i=(r?.formats??[]).filter(e=>ir.includes(e));return{edit:r?.edit===`text`||r?.edit===`rich`?r.edit:void 0,formats:i,type:r?.type??e.type}}function or(e,t){let n=e.value,r=rr.exec(t);if(r)try{let e=typeof n==`string`?JSON.parse(n):n;n=(Array.isArray(e)?e.filter(e=>typeof e==`object`&&!!e):[])[Number(r[2])]?.[r[3]]}catch{return null}return typeof n==`string`||typeof n==`number`?String(n):null}var sr=e=>e.childNodes.length>0&&[...e.childNodes].every(e=>e.nodeType===Node.TEXT_NODE||e.nodeType===Node.COMMENT_NODE),cr=(e,t)=>e.kind===`field`&&Xn(e.source)===Xn(t),lr=()=>R(`The page shows this field differently from how it’s stored, so Lens can’t edit it here. Open it in wp-admin.`,`taw-core`);function ur(e,t,n,r,i){let a=Pn(r,i,t.ownerDocument);if(a.lossy)return{reason:R(`This field has formatting Lens can’t edit on the page, such as images or headings. Open it in wp-admin.`,`taw-core`)};let o=null;for(let r=t;r&&![`BODY`,`HTML`].includes(r.tagName);r=r.parentElement){let t=e.attribute(r);if(!cr(t,n)||t.source.part){if(o)break;continue}let s=Nn(r.innerHTML,i,r.ownerDocument);if(!s.lossy&&Fn(s.blocks,a.blocks))o=r;else if(o)break}return o?{plan:{kind:`field`,mode:`rich`,source:n,el:o,original:r,formats:i,blocks:a.blocks}}:{reason:lr()}}function dr(e,t){let n=t.attribution,r=t.elements[0];if(!r)return null;if(n.kind===`template`)return!sr(r)||tr(r)!==n.source.text?{reason:R(`This text shares its element with other markup. Ask Claude to change it.`,`taw-core`)}:{plan:{kind:`template`,source:n.source,el:r,original:tr(r)}};if(n.kind!==`field`)return null;let i=n.source,a=i.read;if(a.store===`wp`||a.store===`menu`||a.type===`wp`)return null;let o=ar(a,i.path);if(!o.edit)return a.type===`image`||o.type===`image`?null:{reason:R(`This kind of field can’t be edited on the page. Open it in wp-admin.`,`taw-core`)};let s=or(a,i.path);if(s===null)return{reason:lr()};let c=i.part?{...i,part:void 0}:i;return o.edit===`rich`?ur(e,r,c,s,o.formats??[]):i.part?{reason:lr()}:/[\r\n]/.test(s.trim())?{reason:R(`This text has line breaks, which editing on the page would lose. Open it in wp-admin.`,`taw-core`)}:!sr(r)||Q(s)!==tr(r)?{reason:lr()}:{plan:{kind:`field`,mode:`text`,source:i,el:r,original:s}}}function fr(e,t,n){let r=rr.exec(e.path);return{store:e.read.store,object:e.read.object,key:e.read.key,...r?{row:Number(r[2]),sub:r[3]}:{},value:t,expect:n}}function pr(e,t,n,r,i){let a=n.text,o=e=>e.replace(/\n/g,` `);return{text:R(`Change "%1$s" to "%2$s".`,`taw-core`).replace(`%1$s`,a).replace(`%2$s`,r),context:[`<lens-edit file="${n.file}" line="${n.line}">`,`Before: ${o(a)}`,`After: ${o(r)}`,`</lens-edit>`,``,xn(e,[t],i)].join(`
`),chips:Sn(e,[t]),from:`edit`}}var mr=typeof navigator<`u`&&/Mac|iPhone|iPad/.test(navigator.platform||navigator.userAgent)?`⌘`:`Ctrl+`,hr=e=>e.preventDefault();function gr({formats:e,state:t,linkOpen:n,onFormat:r,onLink:i}){let a=(e,t,n,r)=>Y(`button`,{type:`button`,class:`tool`,"aria-label":e,title:e,"aria-pressed":t,onMouseDown:hr,onClick:n,children:r});return Y(`div`,{class:`edit-toolbar`,role:`toolbar`,"aria-label":R(`Formatting`,`taw-core`),children:[e.includes(`bold`)&&a(R(`Bold`,`taw-core`),t.bold,()=>r(`bold`),Y(`span`,{class:`tool-b`,children:`B`})),e.includes(`italic`)&&a(R(`Italic`,`taw-core`),t.italic,()=>r(`italic`),Y(`span`,{class:`tool-i`,children:`I`})),e.includes(`link`)&&a(R(`Link`,`taw-core`),n||t.link,i,Y(Ot,{})),e.includes(`lists`)&&Y(v,{children:[Y(`span`,{class:`tool-rule`,"aria-hidden":`true`}),a(R(`Bulleted list`,`taw-core`),t.ul,()=>r(`ul`),Y(kt,{})),a(R(`Numbered list`,`taw-core`),t.ol,()=>r(`ol`),Y(At,{}))]}),Y(`span`,{class:`tool-note`,children:R(`Allowed by this field`,`taw-core`)})]})}function _r({link:e,pages:t,onUrl:n,onApply:r,onClose:i}){let[a,o]=W(e.url),s=K(null);return G(()=>s.current?.focus(),[]),Y(`div`,{class:`edit-link`,children:[Y(`label`,{for:`lens-link-url`,class:`edit-link-label`,children:e.text?R(`Link for "%s"`,`taw-core`).replace(`%s`,Z(e.text,40)):R(`Link`,`taw-core`)}),Y(`div`,{class:`edit-link-row`,children:[Y(`input`,{id:`lens-link-url`,class:`edit-link-input mono`,type:`text`,list:`lens-link-pages`,value:a,placeholder:`/about/`,autoComplete:`off`,ref:s,onInput:e=>{let t=e.currentTarget.value;o(t),n(t)},onKeyDown:e=>{e.key===`Enter`?(e.preventDefault(),r(a.trim())):e.key===`Escape`&&(e.preventDefault(),e.stopPropagation(),i())}}),Y(`datalist`,{id:`lens-link-pages`,children:t.map(e=>Y(`option`,{value:e.url,children:e.title},e.url))}),Y(`button`,{type:`button`,class:`card-button`,"aria-disabled":a.trim()===``?`true`:void 0,onClick:()=>a.trim()!==``&&r(a.trim()),children:e.existing?R(`Update link`,`taw-core`):R(`Add link`,`taw-core`)}),e.existing&&Y(`button`,{type:`button`,class:`card-button`,onClick:()=>r(``),children:R(`Remove`,`taw-core`)})]}),Y(`span`,{class:`edit-hint`,children:R(`Pages on this site are suggested as you type.`,`taw-core`)})]})}function vr(e){let{plan:t,after:n,before:r,saving:i,error:a,blocked:o}=e,s=t.kind===`field`&&t.mode===`rich`,c=t.kind===`field`?rn(t.source.read,t.source.path):`“${Z(t.source.text,48)}”`,l=t.kind===`field`?`${R(`Field`,`taw-core`)} · ${nn(t.mode===`rich`?`wysiwyg`:t.source.read.subs?.[t.source.path.split(`.`).pop()??``]?.type??t.source.read.type)}`:t.source.how===`i18n`?R(`Template text · translatable`,`taw-core`):R(`Template text`,`taw-core`),u=t.kind===`template`?R(`Type on the page. Template text lives in a theme file, so Save sends the change to Claude, and you apply the diff.`,`taw-core`):R(`Type on the page. Saving writes the field value directly; nothing else changes.`,`taw-core`),d=n!==null&&!i&&o===null;return Y(`section`,{class:`card edit`,"aria-label":R(`Editing on the page`,`taw-core`),children:[Y(`div`,{class:`card-head`,children:[Y(`span`,{class:t.kind===`field`?`card-title mono`:`card-title`,children:c}),Y(`span`,{class:`card-badge`,children:l}),Y(`span`,{class:`edit-status`,children:R(`Editing on the page`,`taw-core`)})]}),Y(`div`,{class:`card-location`,children:u}),s&&Y(gr,{formats:t.formats,state:e.state,linkOpen:e.link!==null,onFormat:e.onFormat,onLink:()=>e.link?e.onLinkClose():e.onLinkOpen()}),s&&e.link&&Y(_r,{link:e.link,pages:e.pages,onUrl:e.onLinkUrl,onApply:e.onLinkApply,onClose:e.onLinkClose}),n!==null&&Y(`div`,{class:`edit-change`,"aria-label":R(`Change`,`taw-core`),children:[Y(`div`,{class:`edit-before`,children:[Y(`span`,{class:`sr-only`,children:[R(`Before:`,`taw-core`),` `]}),Z(r,400)]}),Y(`div`,{class:`edit-after`,children:[Y(`span`,{class:`sr-only`,children:[R(`After:`,`taw-core`),` `]}),Z(n,400)||R(`(empty)`,`taw-core`)]})]}),s&&Y(`div`,{class:`edit-hint edit-note`,children:R(`Saves in TAW’s rich-text format, the same the wp-admin editor writes.`,`taw-core`)}),(a||o)&&Y(`div`,{class:`edit-error`,role:a?`alert`:void 0,children:a??o}),Y(`div`,{class:`card-actions edit-actions`,children:[Y(`button`,{type:`button`,class:`card-button primary`,"aria-disabled":d?void 0:`true`,onMouseDown:hr,onClick:()=>d&&e.onSave(),children:i?Y(v,{children:[Y(wt,{size:12}),t.kind===`template`?R(`Sending…`,`taw-core`):R(`Saving…`,`taw-core`)]}):R(`Save`,`taw-core`)}),Y(`button`,{type:`button`,class:`card-button`,onMouseDown:hr,onClick:e.onCancel,children:R(`Cancel`,`taw-core`)}),Y(`span`,{class:`edit-keys`,children:s?Y(v,{children:[Y(`span`,{class:`mono`,children:[mr,`B`]}),` `,Y(`span`,{class:`mono`,children:[mr,`I`]}),` `,t.formats.includes(`link`)&&Y(`span`,{class:`mono`,children:[mr,`K`]})]}):Y(v,{children:[Y(`span`,{class:`mono`,children:`Enter`}),` `,R(`save`,`taw-core`),` · `,Y(`span`,{class:`mono`,children:`Esc`}),` `,R(`cancel`,`taw-core`)]})})]})]})}function yr({title:e,mono:t,badge:n,onClose:r}){return Y(`div`,{class:`card-head`,children:[Y(`span`,{class:t?`card-title mono`:`card-title`,children:e}),Y(`span`,{class:`card-badge`,children:n}),r&&Y(`button`,{type:`button`,class:`card-close`,"aria-label":R(`Clear selection`,`taw-core`),onClick:r,children:Y(mt,{})})]})}var br=({children:e})=>Y(`div`,{class:`card-actions`,children:e});function xr({edit:e,onEdit:t,onWhy:n}){if(!e)return null;let r=`plan`in e;return Y(`button`,{type:`button`,class:`card-button primary`,"aria-disabled":r?void 0:`true`,title:r?R(`Edit the text on the page (or double-click it)`,`taw-core`):e.reason,onClick:()=>r?t?.():n(e.reason),children:R(`Edit here`,`taw-core`)})}var Sr=({href:e,children:t,primary:n})=>Y(`a`,{class:n?`card-button primary`:`card-button`,href:e,target:`_blank`,rel:`noopener noreferrer`,children:t});function Cr(e){let t=e.read.value,n=/\[(\d+)\]\.(.+)$/.exec(e.path);if(n)try{let e=typeof t==`string`?JSON.parse(t):t;t=Array.isArray(e)?e[Number(n[1])]?.[n[2]]:t}catch{}else if(e.read.type===`repeater`)try{let e=typeof t==`string`?JSON.parse(t):t;if(Array.isArray(e))return e.length===1?R(`1 row`,`taw-core`):R(`%d rows`,`taw-core`).replace(`%d`,String(e.length))}catch{}if(e.read.type===`image`)return e.read.stem??String(t??``);let r=typeof t==`string`||typeof t==`number`?String(t):JSON.stringify(t??``);return Z(/<[a-z][^>]*>/i.test(r)?Wn(r):r,600)}function wr({manifest:e,sources:t,editor:n}){return Y(`ul`,{class:`card-sources`,children:t.map(t=>Y(`li`,{children:[Y(`span`,{class:`card-source-name mono`,children:an(t)}),Y(`span`,{class:`card-source-where`,children:t.kind===`field`?sn(e,t.node,t.label):`${t.file}:${t.line}`}),t.kind===`field`?t.read.admin&&Y(`a`,{class:`card-link`,href:t.read.admin,target:`_blank`,rel:`noopener noreferrer`,children:R(`Open in wp-admin`,`taw-core`)}):Y(Tr,{manifest:e,source:t,editor:n})]},t.kind===`field`?t.path+t.read.object:`${t.file}:${t.line}`))})}function Tr({manifest:e,source:t,editor:n}){let r=e.files[t.file]?.abs;return r?Y(`a`,{class:`card-link`,href:dn(n,r,t.line),children:R(`Open in editor`,`taw-core`)}):null}function Er({manifest:e,node:t}){let n=e.nodes.get(t)?.template;return n?Y(`div`,{class:`card-meta mono`,children:[R(`Rendered by`,`taw-core`),` `,n]}):null}function Dr({manifest:e,target:t,editor:n,edit:r,onEdit:i,onClose:a,onShowInTree:o}){let s=t.attribution,c=e.nodes.get(t.at),[l,u]=W(null);G(()=>u(null),[t]);let d=Y(xr,{edit:r,onEdit:i,onWhy:u}),f=l&&Y(`div`,{class:`card-note card-why`,role:`status`,children:l});if(s.kind===`field`){let{read:n}=s.source,r=tn(n),i=rn(n,s.source.path),o=r?R(`WordPress`,`taw-core`):`${R(`Field`,`taw-core`)} · ${nn(n.subs?.[s.source.path.split(`.`).pop()??``]?.type??n.type)}`;return Y(`section`,{class:`card`,"aria-label":R(`Inspect`,`taw-core`),children:[Y(yr,{title:i,mono:!r,badge:o,onClose:a}),Y(`div`,{class:`card-location`,children:sn(e,s.source.node,s.source.label)}),Y(`div`,{class:`card-value`,children:Cr(s.source)||Y(`span`,{class:`card-empty`,children:R(`(empty)`,`taw-core`)})}),n.default&&Y(`div`,{class:`card-note`,children:R(`The field’s default: nothing is saved yet.`,`taw-core`)}),s.source.part&&Y(`div`,{class:`card-note`,children:R(`Part of this rich-text field.`,`taw-core`)}),Y(Er,{manifest:e,node:t.at}),f,Y(br,{children:[r?null:d,n.admin&&Y(Sr,{href:n.admin,children:R(`Open in wp-admin`,`taw-core`)})]})]})}if(s.kind===`template`){let{source:t}=s,r=e.files[t.file],i=r?.lines??{},o=[t.line-1,t.line,t.line+1].filter(e=>i[String(e)]!==void 0);return Y(`section`,{class:`card`,"aria-label":R(`Inspect`,`taw-core`),children:[Y(yr,{title:`“${Z(t.text,60)}”`,badge:t.how===`i18n`?R(`Template text · translatable`,`taw-core`):R(`Template text`,`taw-core`),onClose:a}),Y(`div`,{class:`card-location`,children:sn(e,t.node)}),o.length>0&&Y(`div`,{class:`card-code mono`,role:`group`,"aria-label":`${t.file}:${t.line}`,children:o.map(e=>Y(`div`,{class:`card-code-line`,"data-current":e===t.line?``:void 0,children:[Y(`span`,{class:`card-code-number`,children:e}),Y(`span`,{class:`card-code-text`,children:i[String(e)]})]},e))}),Y(`div`,{class:`card-meta mono`,children:[t.file,`:`,t.line]}),f,Y(br,{children:[d,r?.abs&&Y(Sr,{href:dn(n,r.abs,t.line),children:R(`Open in editor`,`taw-core`)})]})]})}if(s.kind===`block`){let r=e.nodes.get(s.node);if(!r)return null;let i=r.kind===`block`?`${R(`Block`,`taw-core`)} · ${r.name}`:r.kind===`part`?R(`Template part`,`taw-core`):R(`Page`,`taw-core`),c=r.files??(r.template?[r.template]:[]),l=[...new Set(r.reads.filter(e=>!tn(e)&&e.store!==`option`).map(e=>e.key))],u=r.template?e.files[r.template]:void 0;return Y(`section`,{class:`card`,"aria-label":R(`Inspect`,`taw-core`),children:[Y(yr,{title:on(r),badge:i,onClose:a}),Y(`div`,{class:`card-location`,children:sn(e,r.id)}),Y(`ul`,{class:`card-files mono`,children:c.map(t=>{let r=e.files[t]?.abs;return Y(`li`,{children:r?Y(`a`,{href:dn(n,r),children:t}):t},t)})}),l.length>0&&Y(`div`,{class:`card-meta`,children:[R(`Fields:`,`taw-core`),` `,l.join(`, `)]}),Y(br,{children:[u?.abs&&Y(Sr,{href:dn(n,u.abs),children:R(`Open in editor`,`taw-core`)}),Y(`button`,{type:`button`,class:`card-button`,onClick:()=>o(t),children:R(`Show in tree`,`taw-core`)})]})]})}if(s.kind===`ambiguous`)return Y(`section`,{class:`card`,"aria-label":R(`Inspect`,`taw-core`),children:[Y(yr,{title:`“${Z(s.text,60)}”`,badge:R(`Ambiguous`,`taw-core`),onClose:a}),Y(`div`,{class:`card-location`,children:sn(e,s.node)}),Y(`div`,{class:`card-note`,children:R(`More than one source holds this exact text, so Lens can’t tell which one rendered it. It doesn’t guess:`,`taw-core`)}),Y(wr,{manifest:e,sources:s.sources,editor:n})]});if(s.kind===`mixed`)return Y(`section`,{class:`card`,"aria-label":R(`Inspect`,`taw-core`),children:[Y(yr,{title:`“${Z(s.text,60)}”`,badge:R(`Mixed`,`taw-core`),onClose:a}),Y(`div`,{class:`card-location`,children:sn(e,t.at)}),Y(`div`,{class:`card-note`,children:R(`Template text around values. Made of:`,`taw-core`)}),Y(wr,{manifest:e,sources:s.sources,editor:n}),Y(Er,{manifest:e,node:t.at})]});let p=t.elements[0]?.tagName.toLowerCase()??``,m=c?.template?e.files[c.template]:void 0;return Y(`section`,{class:`card`,"aria-label":R(`Inspect`,`taw-core`),children:[Y(yr,{title:`<${p}>`,mono:!0,badge:R(`Element`,`taw-core`),onClose:a}),Y(`div`,{class:`card-location`,children:sn(e,t.at)}),Y(`div`,{class:`card-note`,children:s.text?R(`Its text isn’t a TAW field or template text: it may be computed in the template, or added by JavaScript.`,`taw-core`):R(`No text of its own. Its template rendered it.`,`taw-core`)}),Y(Er,{manifest:e,node:t.at}),Y(br,{children:m?.abs&&Y(Sr,{href:dn(n,m.abs),children:R(`Open in editor`,`taw-core`)})})]})}function Or(e,t,n=400){return e<120?`left`:e+n>t.width-120?`right`:null}var kr=e=>Math.max(240,Math.min(600,e.height-e.top-24));function Ar(e){return jr({x:e.width-400-24,y:e.top+72},e)}function jr(e,t,n=400){let r=Math.max(12,t.width-n-12),i=t.top+12,a=Math.max(i,t.height-kr(t)-12);return{x:Math.round(Math.min(r,Math.max(12,e.x))),y:Math.round(Math.min(a,Math.max(i,e.y)))}}var Mr=(e,t,n=400)=>e.x+n/2<t.width/2?`left`:`right`;function Nr(e,t=400){return`html { margin-${e}: ${t}px !important; }`}function Pr(e,t,n=400){return{left:t===`left`?n:0,top:e.top,right:t===`right`?e.width-n:e.width,bottom:e.height}}function Fr(e,t,n){let r=Math.max(n.left+1,e.left-t),i=Math.max(n.top+1,e.top-t),a=Math.min(n.right-1,e.left+e.width+t),o=Math.min(n.bottom-1,e.top+e.height+t);return{left:r,top:i,width:Math.max(0,a-r),height:Math.max(0,o-i)}}function Ir([e,t,n]){let r=e=>{let t=e/255;return t<=.04045?t/12.92:((t+.055)/1.055)**2.4};return .2126*r(e)+.7152*r(t)+.0722*r(n)}var Lr=`#1A1C21`,Rr=`#FFFFFF`,zr=[26,28,33],Br=[255,255,255];function Vr(e){let t=t=>{let[n,r]=e>t?[e,t]:[t,e];return(n+.05)/(r+.05)};return t(Ir(zr))>=t(Ir(Br))?{ring:Lr,halo:Rr,luminance:e}:{ring:Rr,halo:Lr,luminance:e}}var Hr=(e,t)=>[e.r*e.a+t[0]*(1-e.a),e.g*e.a+t[1]*(1-e.a),e.b*e.a+t[2]*(1-e.a)];function Ur(e){let t=Br;for(let n=e.length-1;n>=0;n--)t=Hr(e[n],t);return t}function Wr(e,t){let n=e.trim().toLowerCase();if(n===`transparent`||n===``)return{r:0,g:0,b:0,a:0};let r=/^#([0-9a-f]{3,8})$/.exec(n);if(r){let e=r[1],t=e.length<=4?[...e].map(e=>e+e).join(``):e;if(t.length===6||t.length===8){let e=e=>parseInt(t.slice(e,e+2),16);return{r:e(0),g:e(2),b:e(4),a:t.length===8?e(6)/255:1}}}let i=/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/.exec(n);if(i){let e=i[4]===void 0?1:i[4].endsWith(`%`)?parseFloat(i[4])/100:parseFloat(i[4]);return{r:+i[1],g:+i[2],b:+i[3],a:Math.max(0,Math.min(1,e))}}return t?t(e):null}function Gr(e){return e.match(/(?:rgba?|hsla?|oklch|oklab|lab|lch|color)\([^()]*\)|#[0-9a-f]{3,8}\b/gi)??[]}function Kr(e){if(e.length===0)return null;let t=e.reduce((e,t)=>({r:e.r+t.r,g:e.g+t.g,b:e.b+t.b,a:e.a+t.a}),{r:0,g:0,b:0,a:0}),n=e.length;return{r:t.r/n,g:t.g/n,b:t.b/n,a:t.a/n}}function qr(e,t,n){let r=e.left-t,i=e.left+e.width+t,a=e.top-t,o=e.top+e.height+t,s=e.left+e.width/2,c=e.top+e.height/2;return[[r,a],[s,a],[i,a],[i,c],[i,o],[s,o],[r,o],[r,c]].map(([e,t])=>[Math.round(e),Math.round(t)]).filter(([e,t])=>e>=0&&t>=0&&e<n.width&&t<n.height)}var Jr=new Set([`IMG`,`VIDEO`,`CANVAS`]);function Yr(e,t,n){let r=[];for(let i of e.stackAt(t,n)){let a=e.style(i);if(a.visibility===`hidden`||parseFloat(a.opacity)===0)continue;if(Jr.has(i.tagName)){let a=e.media(i,t,n);if(a&&(r.push(a),a.a>=.999))break;continue}let o=a.backgroundImage;if(o&&o!==`none`){let a=/url\((['"]?)(.*?)\1\)/.exec(o)?.[2],s=(a?e.backgroundImage(i,a,t,n):null)??Kr(Gr(o).flatMap(t=>e.color(t)??[]));if(s&&s.a>0&&(r.push(s),s.a>=.999))break}let s=e.color(a.backgroundColor);if(s&&s.a>0&&(r.push(s),s.a>=.999))break}return Ur(r)}function Xr(e,t,n,r){let i=qr(t,n,r),a=(i.length>0?i:[[Math.round(t.left+t.width/2),Math.round(t.top+t.height/2)]]).map(([t,n])=>Ir(Yr(e,t,n)));return Vr(a.reduce((e,t)=>e+t,0)/a.length)}var Zr={hover:4,selected:7,editing:7},Qr=24;function $r({rings:e,sampler:t,top:n,area:r,refresh:i=0}){let[a,o]=Ee(e=>e+1,0),s=K(new Map),c=e.length>0,l=K(i);G(()=>{if(!c)return;let e=0,t=0,n=()=>{e||=requestAnimationFrame(()=>{e=0,o(void 0)}),clearTimeout(t),t=window.setTimeout(()=>{s.current.clear(),o(void 0)},150)};window.addEventListener(`scroll`,n,{capture:!0,passive:!0}),window.addEventListener(`resize`,n,{passive:!0});let r=window.setInterval(()=>o(void 0),600);return()=>{cancelAnimationFrame(e),clearTimeout(t),clearInterval(r),window.removeEventListener(`scroll`,n,{capture:!0}),window.removeEventListener(`resize`,n)}},[c]);let u={width:document.documentElement.clientWidth||window.innerWidth,height:window.innerHeight},d=Oe(()=>(l.current!==i&&(l.current=i,s.current.clear()),e.flatMap(e=>{if(!e.el.isConnected)return[];let n=e.el.getBoundingClientRect();if(n.width<=0&&n.height<=0)return[];let r=Zr[e.state];if(n.bottom+r<0||n.top-r>u.height)return[];let i=s.current.get(e.el);return i||(i=t?Xr(t,n,r,u):{ring:`#1A1C21`,halo:`#FFFFFF`,luminance:1},s.current.set(e.el,i)),[{spec:e,rect:n,outset:r,color:i}]})),[e,t,a,i,u.width,u.height]);return d.length===0?null:Y(`div`,{class:`overlay`,"aria-hidden":`true`,children:d.map(({spec:e,rect:t,outset:i,color:a})=>{let o=r??{left:0,top:n,right:u.width,bottom:u.height},s=Fr(t,i,o);if(s.width<=0||s.height<=0)return null;let c=s.top-6-Qr,l=c>=o.top+2?c:Math.min(s.top+s.height+6,o.bottom-Qr-2),d=Math.max(o.left+4,Math.min(s.left,o.right-120));return Y(`div`,{children:[Y(`div`,{class:`ring`,"data-state":e.state,style:{left:`${s.left}px`,top:`${s.top}px`,width:`${s.width}px`,height:`${s.height}px`,"--ring":a.ring,"--halo":a.halo}}),e.label&&Y(`div`,{class:`ring-label`,style:{left:`${d}px`,top:`${l}px`,maxWidth:`${Math.max(120,o.right-d-8)}px`,"--ring":a.ring,"--halo":a.halo},children:e.label})]},e.key)})})}var ei=`taw-lens:prefs`,ti={mode:`docked`,shown:`docked`,side:`right`,sheet:null,theme:`system`},ni=(e,t,n)=>typeof e==`string`&&t.includes(e)?e:n,ri=e=>typeof e==`number`&&Number.isFinite(e);function ii(e){if(typeof e!=`object`||!e)return{...ti};let t=e,n=t.sheet;return{mode:ni(t.mode,[`docked`,`sheet`,`hidden`],ti.mode),shown:ni(t.shown,[`docked`,`sheet`],ti.shown),side:ni(t.side,[`left`,`right`],ti.side),sheet:typeof n==`object`&&n&&ri(n.x)&&ri(n.y)?{x:n.x,y:n.y}:null,theme:ni(t.theme,[`system`,`light`,`dark`],ti.theme)}}function ai(e=si()){try{let t=e?.getItem(ei);return ii(t?JSON.parse(t):null)}catch{return{...ti}}}function oi(e,t=si()){try{t?.setItem(ei,JSON.stringify(e))}catch{}}function si(){try{return window.localStorage}catch{return null}}var ci=e=>e===`system`?`light`:e===`light`?`dark`:`system`,li=e=>e?e.split(`/`).pop()??e:``;function ui(e,t){let n=t.id===0?[]:e.rootsOf(t.id),r=t.kind;return{id:`n:${t.id}`,kind:r,name:on(t),detail:r===`block`?t.name:r===`page`?li(t.template):t.template??t.name,children:[],target:{key:`n:${t.id}`,attribution:{kind:`block`,node:t.id},elements:n,at:t.id},position:Math.min(...n.map(t=>e.position(t)),2**53-1)}}function di(e,t,n,r){let i=Math.min(...r.map(t=>e.position(t)),2**53-1),a=r[0]?e.nodeOf(r[0]):n.node;if(n.kind===`template`)return{id:t,kind:`template`,name:Z(n.text,40),detail:`${n.file}:${n.line}`,children:[],target:{key:t,attribution:{kind:`template`,node:n.node,text:n.text,source:n},elements:r,at:a},position:i};let o={kind:`field`,node:n.node,read:n.read,path:n.read.key,label:n.read.label},s=tn(n.read);return{id:t,kind:s?`wp`:`field`,name:rn(n.read),detail:R(s?`WordPress`:`field`,`taw-core`),children:[],target:{key:t,attribution:{kind:`field`,node:n.node,text:``,source:o},elements:r,at:a},position:i}}function fi(e){let t=new Map;for(let n of e.manifest.nodes.values())t.set(n.id,ui(e,n));let n=new Map;for(let t of e.sources().values()){let e=t.kind===`field`?Zn(t.read):Xn(t);n.has(e)||n.set(e,{node:t.node,source:t})}for(let[r,{node:i,source:a}]of n){let n=t.get(i),o=e.elementsOf(r);n&&o.length>0&&n.children.push(di(e,r,a,o))}for(let n of e.manifest.nodes.values())n.parent!==null&&t.get(n.parent)?.children.push(t.get(n.id));let r=e=>{for(let t of e.children)e.position=Math.min(e.position,r(t));let t=e=>e.kind===`page`||e.kind===`part`||e.kind===`block`;return e.children.sort((e,n)=>e.position-n.position||Number(t(n))-Number(t(e))),e.position},i=t.get(0);return r(i),i}var pi=(e,t)=>e.name.toLowerCase().includes(t)||e.detail.toLowerCase().includes(t);function mi(e,t,n=``){let r=n.trim().toLowerCase(),i=[],a=new Set;if(r){let t=e=>{let n=pi(e,r);for(let r of e.children)n=t(r)||n;return n&&a.add(e.id),n};t(e)}let o=(e,n,s)=>{if(r&&!a.has(e.id))return;let c=r?!0:t.has(e.id);if(i.push({node:e,depth:n,parent:s,expanded:c&&e.children.length>0}),c)for(let t of e.children)o(t,n+1,e.id)};return o(e,0,null),i}function hi(e,t){let n=[],r=e=>{if(e.id===t)return!0;for(let t of e.children)if(r(t))return n.unshift(e.id),!0;return!1};return r(e)?n:[]}function gi(e){let t=e.attribution;return t.kind===`block`?`n:${t.node}`:t.kind===`field`?Zn(t.source.read):t.kind===`template`?Xn(t.source):null}var _i=({kind:e})=>Y(e===`page`?_t:e===`part`?vt:e===`block`?yt:e===`template`?xt:bt,{});function vi({root:e,selected:t,reveal:n,onHover:r,onSelect:i,onBack:a}){let[o,s]=W(``),[c,l]=W(()=>new Set([e.id])),[u,d]=W(e.id),f=K(u),p=e=>{f.current=e,d(e)},[m,h]=W(null),g=K(new Map),_=K(!1);G(()=>{let r=[...t,...n?[n]:[]].flatMap(t=>hi(e,t));n&&r.push(n),r.length>0&&l(e=>new Set([...e,...r])),n&&(p(n),_.current=!0)},[e,n,t]);let v=Oe(()=>mi(e,c,o),[e,c,o]),y=v[Math.max(0,v.findIndex(e=>e.node.id===u))];De(()=>{_.current&&y&&(g.current.get(y.node.id)?.focus(),g.current.get(y.node.id)?.scrollIntoView?.({block:`nearest`}),_.current=!1)},[y]);let b=(e,t)=>l(n=>{let r=new Set(n);return t??!r.has(e)?r.add(e):r.delete(e),r}),x=e=>{let t=v[Math.max(0,Math.min(v.length-1,e))];t&&(_.current=!0,p(t.node.id),r(t.node.target))};return Y(`div`,{class:`structure`,children:[Y(`div`,{class:`structure-head`,children:[Y(`span`,{class:`structure-title`,id:`lens-structure-title`,children:R(`Structure`,`taw-core`)}),Y(`button`,{type:`button`,class:`text-button`,onClick:a,children:R(`Back to chat`,`taw-core`)})]}),Y(`label`,{class:`sr-only`,for:`lens-tree-filter`,children:R(`Filter`,`taw-core`)}),Y(`input`,{id:`lens-tree-filter`,class:`filter`,type:`search`,autocomplete:`off`,spellcheck:!1,placeholder:R(`Filter blocks, fields, files…`,`taw-core`),value:o,onInput:e=>s(e.currentTarget.value)}),Y(`div`,{class:`tree`,role:`tree`,"aria-labelledby":`lens-structure-title`,onKeyDown:e=>{let t=v.findIndex(e=>e.node.id===f.current),n=Math.max(0,t),r=v[n];if(!r)return;let{node:a,expanded:o,parent:s}=r;switch(e.key){case`ArrowDown`:x(n+1);break;case`ArrowUp`:x(n-1);break;case`Home`:x(0);break;case`End`:x(v.length-1);break;case`ArrowRight`:a.children.length>0&&!o?b(a.id,!0):o&&x(n+1);break;case`ArrowLeft`:o?b(a.id,!1):s&&x(v.findIndex(e=>e.node.id===s));break;case`Enter`:case` `:i(a.target,e.shiftKey);break;default:return}e.preventDefault()},onMouseLeave:()=>{h(null),r(null)},children:[v.map(({node:e,depth:n,expanded:a})=>{let o=t.has(e.id),s=e.target.elements.length>0;return Y(`div`,{ref:t=>{t?g.current.set(e.id,t):g.current.delete(e.id)},class:`tree-row`,role:`treeitem`,"aria-level":n+1,"aria-selected":o,"aria-expanded":e.children.length>0?a:void 0,tabIndex:e.id===y?.node.id?0:-1,"data-selected":o?``:void 0,style:{paddingLeft:`${8+n*16}px`},onMouseEnter:()=>{h(e.id),r(e.target)},onFocus:()=>p(e.id),onClick:t=>{p(e.id),i(e.target,t.shiftKey)},children:[e.children.length>0?Y(`span`,{class:`tree-twisty`,onClick:t=>{t.stopPropagation(),b(e.id)},children:Y(a?ht:gt,{})}):Y(`span`,{class:`tree-twisty`}),Y(`span`,{class:`tree-icon`,children:Y(_i,{kind:e.kind})}),Y(`span`,{class:`tree-name`,children:e.name}),Y(`span`,{class:`tree-detail mono`,children:e.detail}),m===e.id&&s&&Y(`span`,{class:`tree-hint`,children:R(`on page`,`taw-core`)})]},e.id)}),v.length===0&&Y(`div`,{class:`tree-none`,children:R(`Nothing matches.`,`taw-core`)})]}),Y(`div`,{class:`structure-help`,children:R(`Hover a row to ring it on the page. Click to select; shift-click to add it to the chat.`,`taw-core`)})]})}var yi={bold:!1,italic:!1,link:!1,ul:!1,ol:!1},bi=e=>e.kind===`field`&&e.mode===`rich`?zn(e.blocks):Q(e.original);function xi(e,t){if(e.kind===`field`&&e.mode===`rich`){let n=Nn(t.html(),e.formats,t.el.ownerDocument).blocks;if(Fn(n,e.blocks))return null;let r=zn(n,t.el.ownerDocument);return r===zn(e.blocks,t.el.ownerDocument)?`${r} (${R(`formatting changed`,`taw-core`)})`:r}let n=t.text();return n===Q(e.original)?null:n}var Si=e=>R(`Editing · %s`,`taw-core`).replace(`%s`,e.kind===`field`?rn(e.source.read,e.source.path):`${e.source.file.split(`/`).pop()??e.source.file}:${e.source.line}`),Ci=new WeakMap,wi=0,Ti=e=>{let t=Ci.get(e);return t||(t=`e:${++wi}`,Ci.set(e,t)),t},Ei=e=>R(e===`light`?`Theme: light. Switch to dark`:e===`dark`?`Theme: dark. Follow the system`:`Theme: follows the system. Switch to light`,`taw-core`),Di=({theme:e})=>Y(e===`light`?dt:e===`dark`?ft:ut,{});function Oi({host:e,initial:t,env:n}){let[r,i]=W(t),[a,o]=W(()=>n.viewport()),[s,c]=W(null),[l,u]=W(null),[d,f]=W(``),p=K(null),m=K(null),h=K(null),g=ke(e=>i(t=>({...t,...e})),[]),_=n.page,[v,y]=W(void 0),b=v??null,[x,S]=W(!1),[C,ee]=W(`chat`),[w,T]=W([]),[E,D]=W(null),[O,k]=W(null),[A,te]=W(null),[ne,re]=W(0),[ie,ae]=W(!0),[j,oe]=W(n.bridge?`connecting`:`off`),[M,se]=W(Ue),[N,P]=W(``),F=K(null),ce=K(j),le=K(null),ue=K(null),de=K(!0),fe=K(null),[I,L]=W(null),z=K(null),[pe,me]=W([]),B=K(0),[V,he]=W(null),[ge,_e]=W([]),ve=K(0),ye=K({save:()=>void 0,cancel:()=>void 0,link:()=>void 0});G(()=>{if(!n.bridge)return;let e=e=>{e.type===`approval`?f(R(`Claude needs your OK for a change`,`taw-core`)):e.type===`done`&&f(e.outcome===`ok`?R(`Claude finished`,`taw-core`):e.outcome===`stopped`?R(`Stopped`,`taw-core`):R(`Claude stopped before finishing`,`taw-core`))},t=n.bridge({status(e){ce.current===`connected`&&e!==`connected`&&se(Ye),ce.current=e,oe(e)},event(t){se(e=>Je(e,t)),e(t)}});return F.current=t,()=>{t.close(),F.current=null}},[n]),De(()=>{let e=ue.current;e&&de.current&&(e.scrollTop=e.scrollHeight)},[M]);let H=()=>{let e=ue.current;e&&(de.current=e.scrollHeight-e.scrollTop-e.clientHeight<48)},be=ke(()=>{let e=_?.map(!0)??null;return y(e),e},[_]);G(()=>_?.onAsset(()=>re(e=>e+1)),[_]);let xe=ke((e,t)=>({key:Ti(t),attribution:e?e.attribute(t):{kind:`element`,node:0,text:``},elements:[t],at:e?e.nodeOf(t):0}),[]),U=ke(e=>{let t=z.current;t&&(e&&t.restore(),t.stop()),z.current=null,L(null)},[]),Se=ke((e,t)=>{U(!0),T(n=>t?n.some(t=>t.key===e.key)?n.filter(t=>t.key!==e.key):[...n,e]:[e]),D(e.key),ae(!0),f(`${R(`Selected`,`taw-core`)} ${b?un(b.manifest,e):``}`.trim())},[b,U]),Ce=()=>{U(!0),T([]),D(null),f(R(`Selection cleared`,`taw-core`))};G(()=>{if(!x||!_||r.mode===`hidden`)return;if(v===void 0){be();return}let e=b,t=_.pick({hover:t=>k(t?{target:xe(e,t),label:!0}:null),pick:(t,n)=>{let r=xe(e,t);Se(r,n);let i=gi(r);i&&te(i)},edit:t=>{let n=xe(e,t);Se(n,!1),Fe.current(n)},stop:()=>S(!1)});return()=>{t(),k(null)}},[x,_,r.mode,v,b,be,xe,Se]);let we=Oe(()=>b?fi(b):null,[b]),Te=Oe(()=>new Set(w.map(gi).filter(e=>e!==null)),[w]),Ee=()=>{if(C===`tree`){ee(`chat`);return}be(),ee(`tree`)},Ae=e=>{b||be(),ee(`tree`),te(gi(e))},je=(e,t)=>{Se(e,t),e.elements[0]&&_?.reveal(e.elements[0])},q=w.find(e=>e.key===E)??w[w.length-1]??null,Me=Oe(()=>b&&q&&_?.edit?dr(b,q):null,[b,q,_]),Ne=(e,t,n,r)=>{_?.patch?.(e,t,n,r);let i=be();i&&T(e=>e.map(e=>e.key.startsWith(`e:`)&&e.elements[0]?xe(i,e.elements[0]):e))},Pe=e=>{if(!b||!_?.edit)return!1;let t=dr(b,e);if(!t||!(`plan`in t))return!1;U(!0),S(!1);let n=t.plan,r=n.el.innerHTML;return z.current=_.edit(n.el,n.kind===`field`&&n.mode===`rich`?`rich`:`text`,{change:()=>L(e=>e&&z.current?{...e,after:xi(e.plan,z.current),error:null}:e),select:()=>L(e=>e&&z.current?{...e,state:z.current.state()}:e),save:()=>ye.current.save(),cancel:()=>ye.current.cancel(),link:()=>ye.current.link()}),L({plan:n,target:e,html:r,after:null,saving:!1,error:null,link:null,state:yi}),ae(!0),ee(`chat`),f(n.kind===`field`&&n.mode===`rich`?`${Si(n)}. ${R(`Esc cancels.`,`taw-core`)}`:`${Si(n)}. ${R(`Enter saves, Esc cancels.`,`taw-core`)}`),!0},Fe=K(Pe);Fe.current=Pe;let J=j===`connected`,Ie=async e=>{let t=F.current;if(!t||!J||M.working||e.text.trim()===``)return;de.current=!0;let n=await t.send(`/message`,e);return n.ok?(le.current=e,!0):(f(n.status===409?R(`Claude is still working on the last message`,`taw-core`):R(`Lens couldn’t send that. Is taw lens still running?`,`taw-core`)),!1)},Le=async()=>{let e=N.trim();if(e===``)return;let t={text:e,context:xn(b?.manifest??null,w,{url:_?.url??``,title:b?.manifest.nodes.get(0)?.label??``}),chips:Sn(b?.manifest??null,w)};P(``),await Ie(t)?ae(!1):P(e)},Re=e=>{let t=le.current;Ie(t&&t.text===e.text?t:{text:e.text,context:``,chips:e.chips})},ze=async(e,t)=>{se(t=>Xe(t,e,`sending`)),(await F.current?.send(`/approve`,{id:e,allow:t}))?.ok||(se(t=>Xe(t,e,`pending`)),f(R(`Lens couldn’t send your answer. Try again.`,`taw-core`)))},Be=()=>void F.current?.send(`/stop`,{}),Ve=()=>void F.current?.send(`/take-over`,{}),He=I?.plan.kind===`template`?J?M.working?R(`Claude is still working on the last message. Save when it’s done.`,`taw-core`):null:R(`Template text is saved by Claude: start taw lens to send this.`,`taw-core`):null,We=async()=>{let e=I,t=z.current;if(!e||!t||e.saving)return;let r=e.plan;if(xi(r,t)===null){U(!1);return}if(r.kind===`template`){if(He||!b){L({...e,error:He});return}L({...e,saving:!0,error:null});let n=M.turns.length;if(!await Ie(pr(b.manifest,e.target,r.source,t.text(),{url:_?.url??``,title:b.manifest.nodes.get(0)?.label??``}))){L(e=>e&&{...e,saving:!1,error:R(`Lens couldn’t send that to Claude. Is taw lens still running?`,`taw-core`)});return}t.stop(),z.current=null,L(null),ae(!1),he({index:n,el:r.el,html:e.html,file:r.source.file,label:Si(r)});return}if(!n.fields){L({...e,error:R(`Lens can’t save fields on this page. Reload it.`,`taw-core`)});return}let i=r.mode===`rich`?Rn(Nn(t.html(),r.formats,r.el.ownerDocument).blocks):t.text();L({...e,saving:!0,error:null});let a=await n.fields.save(fr(r.source,i,r.original));if(!a.ok){L(e=>e&&{...e,saving:!1,error:a.message});return}r.mode===`text`&&a.saved!==i&&(r.el.textContent=a.saved),t.stop(),z.current=null,L(null);let o=rn(r.source.read,r.source.path);me(t=>[...t,{id:++B.current,after:M.turns.length,name:o,state:`saved`,undo:fr(r.source,a.previous,a.saved),source:r.source,el:r.el,html:e.html}]),de.current=!0,Ne(r.source.read,r.source.path,a.saved,a.isDefault),f(`${R(`Saved to`,`taw-core`)} ${o}`)},Ge=async e=>{let t=pe.find(t=>t.id===e);if(!t||!n.fields||t.state!==`saved`)return;let r=t=>me(n=>n.map(n=>n.id===e?{...n,...t}:n));r({state:`undoing`,error:void 0});let i=await n.fields.save(t.undo);if(!i.ok){r({state:`saved`,error:i.message});return}t.el.isConnected&&(t.el.innerHTML=t.html),r({state:`undone`}),Ne(t.source.read,t.source.path,i.saved,i.isDefault),f(`${R(`Undid the change to`,`taw-core`)} ${t.name}`)},Ke=()=>{let e=z.current;if(!e)return;e.keepSelection();let t=e.linkUrl();_e([]),L(n=>n&&{...n,link:{text:e.linkText(),url:t,existing:t!==``}})},qe=()=>{L(e=>e&&{...e,link:null}),z.current?.focus()},Ze=e=>{let t=z.current;t?.link(e),L(e=>e&&t&&{...e,link:null,after:xi(e.plan,t),state:t.state()})},Qe=e=>{window.clearTimeout(ve.current),ve.current=window.setTimeout(()=>{n.fields?.pages(e).then(_e)},200)};ye.current={save:()=>void We(),cancel:()=>{U(!0),f(R(`Edit cancelled`,`taw-core`))},link:Ke},G(()=>{if(!V)return;let e=M.turns[V.index];e&&e.outcome!==void 0&&(!(e.changed??[]).some(e=>e===V.file||e.endsWith(`/${V.file}`))&&V.el.isConnected&&(V.el.innerHTML=V.html),he(null))},[M,V]),G(()=>{(x||r.mode===`hidden`)&&U(!0)},[x,r.mode,U]),G(()=>()=>U(!0),[U]),G(()=>{if(!(!_?.onDoubleClick||x||w.length===0||I))return _.onDoubleClick(e=>{let t=w.find(t=>t.elements.some(t=>t===e||t.contains(e)));return t?Fe.current(t):!1})},[_,x,w,I]);let $e=e=>{e.key===`Enter`&&!e.shiftKey&&!e.isComposing&&(e.preventDefault(),Le())};De(()=>{let e=fe.current;e&&(e.style.height=`auto`,e.style.height=`${Math.min(e.scrollHeight,132)}px`)},[N]);let et=I?.plan.el??V?.el??null,tt=r.mode===`hidden`?[]:[...et?[{key:`editing`,el:et,state:`editing`,label:I?Si(I.plan):V?.label}]:[],...w.flatMap(e=>e.elements.filter(e=>e!==et&&!(I&&et?.contains(e))).map((t,n)=>({key:`${e.key}:${n}`,el:t,state:`selected`,label:n===0&&b?ln(b.manifest,e):void 0}))),...O?O.target.elements.filter(e=>!w.some(t=>t.elements.includes(e))).map((e,t)=>({key:`hover:${t}`,el:e,state:`hover`,label:O.label&&t===0&&b?ln(b.manifest,O.target):void 0})):[]];G(()=>n.push(r.mode===`docked`?r.side:null),[n,r.mode,r.side]),G(()=>n.theme(r.theme),[n,r.theme]),G(()=>n.save(r),[n,r]),G(()=>n.onResize(()=>o(n.viewport())),[n]),G(()=>{h.current===`reopen`?p.current?.focus():h.current===`hide`&&m.current?.focus(),h.current=null},[r.mode]);let X=jr(r.sheet??Ar(a),a),ut=e=>{g({mode:`docked`,shown:`docked`,side:e}),f(R(e===`left`?`Lens docked on the left`:`Lens docked on the right`,`taw-core`))},dt=()=>{g({mode:`sheet`,shown:`sheet`,sheet:X}),f(R(`Lens undocked into a sheet`,`taw-core`))},ft=()=>{h.current=`reopen`,S(!1),g({mode:`hidden`})},mt=()=>{h.current=`hide`,g({mode:r.shown})},ht=e=>{r.mode!==`sheet`||e.button!==0||e.target.closest(`button`)||(e.currentTarget.setPointerCapture?.(e.pointerId),c({pointer:e.pointerId,start:{x:e.clientX,y:e.clientY},from:X}),e.preventDefault())},gt=e=>{if(!s||e.pointerId!==s.pointer)return;let t=jr({x:s.from.x+e.clientX-s.start.x,y:s.from.y+e.clientY-s.start.y},a);g({sheet:t}),u(Or(t.x,a))},_t=e=>{s&&e.pointerId===s.pointer&&(c(null),u(null),l&&ut(l))};G(()=>{if(!s)return;let e=e=>{e.key===`Escape`&&(g({sheet:s.from}),c(null),u(null))};return document.addEventListener(`keydown`,e,!0),()=>document.removeEventListener(`keydown`,e,!0)},[s,g]);let vt=Y(`div`,{class:`sr-only`,role:`status`,"aria-live":`polite`,children:d});if(r.mode===`hidden`)return Y(`div`,{class:`lens`,children:[Y(`button`,{ref:p,type:`button`,class:`reopen`,"data-side":r.side,"aria-label":R(`Show Lens`,`taw-core`),title:R(`Show Lens`,`taw-core`),onClick:mt,children:Y(nt,{})}),vt]});let yt=r.mode===`docked`,bt=yt?{"--lens-top":`${a.top}px`}:{left:`${X.x}px`,top:`${X.y}px`,height:`${kr(a)}px`};return Y(`div`,{class:`lens`,children:[l&&Y(`div`,{class:`dock-zone`,"data-side":l,"aria-hidden":`true`,style:{"--lens-top":`${a.top}px`},children:Y(`span`,{class:`dock-zone-label`,children:R(l===`left`?`Release to dock left`:`Release to dock right`,`taw-core`)})}),Y(`aside`,{class:`panel`,"aria-label":R(`TAW Lens`,`taw-core`),"data-mode":r.mode,"data-side":r.side,"data-dragging":s?``:void 0,style:bt,children:[Y(`div`,{class:`header`,onPointerDown:ht,onPointerMove:gt,onPointerUp:_t,onPointerCancel:_t,children:[Y(`div`,{class:`brand`,children:[yt?Y(nt,{}):Y(`span`,{class:`grip`,children:Y(pt,{})}),Y(`span`,{class:`brand-name`,children:R(`Lens`,`taw-core`)}),yt&&e&&J&&Y(`span`,{class:`brand-host`,children:e}),j===`connecting`?Y(`span`,{class:`status`,children:[Y(wt,{size:12}),R(`Connecting…`,`taw-core`)]}):j===`connected`?null:Y(`span`,{class:`status`,children:[Y(`span`,{class:`status-dot`,"aria-hidden":`true`}),R(j===`outdated`?`Restart needed`:`Not connected`,`taw-core`)]})]}),Y(`div`,{class:`tools`,children:[Y(`button`,{type:`button`,class:`icon-button`,"aria-label":R(`Pick an element`,`taw-core`),title:R(x?`Stop picking (Esc)`:`Pick an element`,`taw-core`),"aria-pressed":x,"aria-disabled":_?void 0:`true`,onClick:()=>_&&S(!x),children:Y(rt,{})}),Y(`button`,{type:`button`,class:`icon-button`,"aria-label":R(`Structure tree`,`taw-core`),title:R(`Structure tree`,`taw-core`),"aria-pressed":C===`tree`,"aria-disabled":_?void 0:`true`,onClick:()=>_&&Ee(),children:Y(it,{})}),Y(`button`,{type:`button`,class:`icon-button`,"aria-label":Ei(r.theme),title:Ei(r.theme),onClick:()=>g({theme:ci(r.theme)}),children:Y(Di,{theme:r.theme})}),yt?Y(`button`,{type:`button`,class:`icon-button`,"aria-label":R(`Undock into a sheet`,`taw-core`),title:R(`Undock into a sheet`,`taw-core`),onClick:dt,children:Y(at,{})}):Y(`button`,{type:`button`,class:`icon-button`,"aria-label":R(`Dock as Inspector`,`taw-core`),title:R(`Dock as Inspector`,`taw-core`),onClick:()=>ut(Mr(X,a)),children:Y(ot,{})}),Y(`button`,{ref:m,type:`button`,class:`icon-button`,"aria-label":R(`Hide Lens`,`taw-core`),title:R(`Hide Lens`,`taw-core`),onClick:ft,children:yt?Y(st,{side:r.side}):Y(ct,{})})]})]}),Y(`div`,{class:`body`,ref:ue,onScroll:H,children:C===`tree`?we?Y(vi,{root:we,selected:Te,reveal:A,onHover:e=>k(e?{target:e,label:!1}:null),onSelect:je,onBack:()=>ee(`chat`)}):Y(`div`,{class:`empty`,children:[Y(`div`,{class:`empty-title`,children:R(`No source map on this page`,`taw-core`)}),Y(`div`,{class:`empty-text`,children:R(`Lens maps what TAW renders. Reload the page; if it stays empty, check that the theme calls wp_footer().`,`taw-core`)})]}):M.turns.length>0||M.busy&&J||pe.length>0?Y(Yt,{chat:M,connection:j,notes:pe,onAnswer:(e,t)=>void ze(e,t),onRetry:Re,onTakeOver:Ve,onUndo:e=>void Ge(e)}):j===`connecting`?Y(Kt,{}):j===`connected`?q?Y(`div`,{class:`empty`,children:Y(`div`,{class:`empty-text`,children:R(`No conversation yet. Select something on the page, or tell Claude what to change.`,`taw-core`)})}):Y(`div`,{class:`empty`,children:[Y(rt,{size:22}),Y(`div`,{class:`empty-title`,children:R(`Point at anything on the page`,`taw-core`)}),Y(`div`,{class:`empty-text`,children:R(`Click an element to see what TAW knows about it, or open the structure tree. Then tell Claude what to change.`,`taw-core`)}),Y(`div`,{class:`gestures`,children:[Y(`span`,{children:[Y(`span`,{class:`kbd`,children:R(`Click`,`taw-core`)}),` `,R(`select`,`taw-core`)]}),Y(`span`,{children:[Y(`span`,{class:`kbd`,children:R(`⇧ Click`,`taw-core`)}),` `,R(`add to selection`,`taw-core`)]}),Y(`span`,{children:[Y(`span`,{class:`kbd`,children:R(`Esc`,`taw-core`)}),` `,R(`stop picking`,`taw-core`)]})]})]}):Y(Gt,{outdated:j===`outdated`})}),C===`chat`&&I?Y(vr,{plan:I.plan,before:bi(I.plan),after:I.after,saving:I.saving,error:I.error,blocked:He,state:I.state,link:I.link,pages:ge,onSave:()=>void We(),onCancel:()=>ye.current.cancel(),onFormat:e=>z.current?.format(e),onLinkOpen:Ke,onLinkClose:qe,onLinkUrl:Qe,onLinkApply:Ze}):C===`chat`&&ie&&q&&b&&_&&Y(Dr,{manifest:b.manifest,target:q,editor:_.editor,edit:Me,onEdit:()=>Pe(q),onClose:Ce,onShowInTree:Ae}),w.length>0&&(C===`tree`||w.length>1||!ie||I)&&Y(`div`,{class:`chips`,role:`list`,"aria-label":R(`Selection`,`taw-core`),children:[w.map(e=>Y(`span`,{role:`listitem`,children:Y(`button`,{type:`button`,class:`chip mono`,"data-primary":w.length>1&&e.key===q?.key?``:void 0,onMouseEnter:()=>k({target:e,label:!1}),onMouseLeave:()=>k(null),onFocus:()=>k({target:e,label:!1}),onBlur:()=>k(null),onClick:()=>{D(e.key),ae(!0),ee(`chat`)},children:b?un(b.manifest,e):e.key})},e.key)),w.length===1&&Y(`span`,{class:`chips-hint`,children:R(`Shift-click to add more`,`taw-core`)})]}),Y(`div`,{class:`composer`,children:[Y(`label`,{class:`sr-only`,for:`lens-message`,children:R(`Message`,`taw-core`)}),Y(`div`,{class:`composer-box`,"data-disabled":J&&!M.working?void 0:``,children:[Y(`textarea`,{id:`lens-message`,ref:fe,class:`composer-input`,rows:1,value:N,disabled:!J||M.working,placeholder:j===`off`||j===`outdated`?R(`Start Lens to chat`,`taw-core`):M.working?R(`Claude is working…`,`taw-core`):R(`Tell Claude what to change…`,`taw-core`),onInput:e=>P(e.currentTarget.value),onKeyDown:$e}),M.working&&J?Y(`button`,{type:`button`,class:`stop`,onClick:Be,children:[Y(Dt,{}),R(`Stop`,`taw-core`)]}):Y(`button`,{type:`button`,class:`send`,"aria-label":R(`Send`,`taw-core`),disabled:!J||N.trim()===``,onClick:()=>void Le(),children:Y(lt,{})})]})]})]}),Y($r,{rings:tt,sampler:_?.sampler??null,top:a.top,area:Pr(a,r.mode===`docked`?r.side:null),refresh:ne}),vt]})}var ki=`taw-lens-pick`,$=24;function Ai(e,t){try{let n=e.createElement(`canvas`);n.width=$,n.height=$;let r=n.getContext(`2d`,{willReadFrequently:!0});return r?(r.drawImage(t,0,0,$,$),{data:r.getImageData(0,0,$,$).data}):null}catch{return null}}function ji(e,t,n,r){if(!e||t.width<=0||t.height<=0)return null;let i=Math.min(23,Math.max(0,Math.floor((n-t.left)/t.width*$))),a=(Math.min(23,Math.max(0,Math.floor((r-t.top)/t.height*$)))*$+i)*4,o=e.data;return{r:o[a],g:o[a+1],b:o[a+2],a:o[a+3]/255}}function Mi(e,t,n){let r=e.defaultView??window,i=new WeakMap,a=new Map,o=new Map,s,c=t=>{if(s===void 0&&(s=e.createElement(`canvas`).getContext(`2d`,{willReadFrequently:!0})),!s)return null;s.clearRect(0,0,1,1),s.fillStyle=`#000`,s.fillStyle=t,s.fillRect(0,0,1,1);let[n,r,i,a]=s.getImageData(0,0,1,1).data;return{r:n,g:r,b:i,a:a/255}};return{stackAt:(n,r)=>e.elementsFromPoint(n,r).filter(e=>e!==t&&!t.contains(e)),style:e=>r.getComputedStyle(e),color(e){return o.has(e)||o.set(e,Wr(e,c)),o.get(e)??null},media(t,n,r){let a=t,o=t.currentSrc||t.src||`canvas`;if(t.tagName===`IMG`&&!t.complete)return null;let s=i.get(t);return(!s||s.key!==o)&&(s={key:o,pixels:Ai(e,a)},i.set(t,s)),ji(s.pixels,t.getBoundingClientRect(),n,r)},backgroundImage(t,r,i,o){let s=a.get(r);if(!s){let t=new Image,i={image:t,pixels:null,ready:!1};t.crossOrigin=`anonymous`,t.onload=()=>{i.pixels=Ai(e,t),i.ready=!0,n()},t.onerror=()=>{i.ready=!0},t.src=new URL(r,e.baseURI).href,a.set(r,i),s=i}return s.ready?ji(s.pixels,t.getBoundingClientRect(),i,o):null}}}var Ni=[`pointerdown`,`pointerup`,`mousedown`,`mouseup`,`click`,`dblclick`,`auxclick`,`touchstart`];function Pi(e,t,n){let r=e.defaultView??window,i=e=>e.composedPath().includes(t),a=n=>{let r=n.target;return!(r instanceof Element)||r===t||t.contains(r)||r===e.documentElement?null:r},o=e=>n.hover(i(e)?null:a(e)),s=e=>{e.relatedTarget||n.hover(null)},c=e=>{if(!i(e)&&(e.preventDefault(),e.stopImmediatePropagation(),e.type===`click`||e.type===`dblclick`)){let t=a(e);t&&e.type===`click`?n.pick(t,e.shiftKey):t&&n.edit?.(t)}},l=e=>{e.key===`Escape`&&(e.preventDefault(),e.stopImmediatePropagation(),n.stop())},u=e.getElementById(ki);u||(u=e.createElement(`style`),u.id=ki,u.textContent=`html, html * { cursor: crosshair !important; }`,e.head.appendChild(u)),r.addEventListener(`pointermove`,o,!0),e.addEventListener(`mouseout`,s,!0);for(let e of Ni)r.addEventListener(e,c,{capture:!0,passive:!1});return r.addEventListener(`keydown`,l,!0),()=>{r.removeEventListener(`pointermove`,o,!0),e.removeEventListener(`mouseout`,s,!0);for(let e of Ni)r.removeEventListener(e,c,{capture:!0});r.removeEventListener(`keydown`,l,!0),e.getElementById(ki)?.remove(),n.hover(null)}}function Fi(e,t){let n=$t(e);return n?new nr(e,n,t):null}function Ii(e,t,n){let r=e.defaultView??window,i=e=>{let r=e.target;r instanceof Element&&r!==t&&!t.contains(r)&&n(r)&&(e.preventDefault(),e.stopImmediatePropagation())};return r.addEventListener(`dblclick`,i,!0),()=>r.removeEventListener(`dblclick`,i,!0)}function Li(e,t,n,r,i){let a=e.getElementById(Xt);if(!a?.textContent)return;let o;try{o=JSON.parse(a.textContent)}catch{return}let s=/^(.+)\[(\d+)\]\.(.+)$/.exec(n),c=Array.isArray(o.nodes)?o.nodes:o.nodes&&typeof o.nodes==`object`?Object.values(o.nodes):[];for(let e of c)for(let n of e.reads??[])if(n.store===t.store&&String(n.object)===String(t.object)&&n.key===t.key){if(s){let e=typeof n.value==`string`;try{let t=e?JSON.parse(n.value):n.value,i=t.filter(e=>typeof e==`object`&&!!e);i[Number(s[2])]&&(i[Number(s[2])][s[3]]=r),n.value=e?JSON.stringify(t):t}catch{continue}}else n.value=r;i?n.default=!0:delete n.default}a.textContent=JSON.stringify(o)}var Ri={surface:`#FFFFFF`,line:`#ECEDF0`,text:`#1A1C21`,muted:`#5F6672`,mutedSoft:`#6B7280`,bubble:`#F4F5F7`,chip:`#F0F1F3`,accent:`#1A1C21`,onAccent:`#FFFFFF`,buttonLine:`#DDE0E5`,composerLine:`#E3E5E9`,amberLine:`#F0DDB6`,amberFill:`#FFFBF3`,amberDot:`#C98512`,amberText:`#6B5A35`,amberRule:`#F3E7CC`,diffRemovedFill:`#FDEEEF`,diffRemovedText:`#9F2430`,diffAddedFill:`#EAF6EF`,diffAddedText:`#1C6B3E`},zi={surface:`#17191E`,line:`#262A31`,text:`#E7E9ED`,muted:`#9AA1AD`,mutedSoft:`#9AA1AD`,bubble:`#22252C`,chip:`#262A31`,accent:`#E7E9ED`,onAccent:`#17191E`,buttonLine:`#3A3F4B`,composerLine:`#30343C`,amberLine:`#4A3D22`,amberFill:`#1E1A12`,amberDot:`#E0A33E`,amberText:`#C9B68E`,amberRule:`#3A3122`,diffRemovedFill:`#3A1F24`,diffRemovedText:`#F4B9BF`,diffAddedFill:`#173023`,diffAddedText:`#A6E0BE`},Bi={dockedWidth:`400px`,sheetWidth:`400px`,sheetHeight:`600px`,sheetRadius:`12px`,sheetShadow:`0 1px 2px rgba(20,24,33,.05), 0 12px 32px rgba(20,24,33,.08)`,fontUi:`Inter, system-ui, -apple-system, "Segoe UI", sans-serif`,fontMono:`"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace`},Vi=e=>`--lens-${e.replace(/[A-Z]/g,e=>`-${e.toLowerCase()}`)}`,Hi=e=>Object.entries(e).map(([e,t])=>`${Vi(e)}: ${t};`).join(` `);function Ui(){return[`:host { ${Hi(Ri)} ${Hi(Bi)} color-scheme: light; }`,`@media (prefers-color-scheme: dark) { :host(:not([data-theme="light"])) { ${Hi(zi)} color-scheme: dark; } }`,`:host([data-theme="dark"]) { ${Hi(zi)} color-scheme: dark; }`].join(`
`)}var Wi=String.raw,Gi=()=>Ui()+Wi`
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

        @media (prefers-reduced-motion: no-preference) {
            .icon-button,
            .send,
            .reopen {
                transition:
                    background-color 0.12s ease,
                    color 0.12s ease;
            }
        }
    `,Ki=`taw-lens`,qi=`taw-lens-push`,Ji=`vscode://file/{path}:{line}`;function Yi(e,t,n=Ji){let r=e.defaultView??window,i=new Set,a;return{map(n=!1){return(n||a===void 0)&&(a=Fi(e,t)),a},pick:n=>Pi(e,t,n),sampler:Mi(e,t,()=>i.forEach(e=>e())),onAsset(e){return i.add(e),()=>i.delete(e)},reveal(e){let t=e.getBoundingClientRect();(t.bottom<0||t.top>r.innerHeight)&&e.scrollIntoView({block:`center`,behavior:`smooth`})},editor:n,url:r.location.href,edit:(e,t,n)=>I(e,t,n),onDoubleClick:n=>Ii(e,t,n),patch(t,n,r,i){Li(e,t,n,r,i)}}}function Xi(e,t){let n=e.defaultView??window,r=window.tawLens;return{viewport(){let t=e.getElementById(`wpadminbar`),r=t&&n.getComputedStyle(t).position===`fixed`?Math.max(0,t.getBoundingClientRect().bottom):0;return{width:e.documentElement.clientWidth||n.innerWidth,height:n.innerHeight,top:r}},push(t){let n=e.getElementById(qi);if(t===null){n?.remove();return}n||(n=e.createElement(`style`),n.id=qi,e.head.appendChild(n)),n.textContent=Nr(t)},theme(e){e===`system`?t.removeAttribute(`data-theme`):t.setAttribute(`data-theme`,e)},save:e=>oi(e),onResize(e){return n.addEventListener(`resize`,e),()=>n.removeEventListener(`resize`,e)},page:Yi(e,t,window.tawLens?.editor||Ji),fields:r?.field?z({field:r.field,search:r.search??``,nonce:r.nonce??``,home:n.location.origin}):void 0,bridge:r?.bridge?e=>F({bridge:r.bridge,nonce:r.nonce??``,protocol:1},e):void 0}}function Zi(e=document){let t=e.querySelector(Ki);if(t)return t;let n=e.createElement(Ki);e.documentElement.appendChild(n);let r=n.attachShadow({mode:`open`}),i=e.createElement(`style`);i.textContent=Gi(),r.appendChild(i);let a=e.createElement(`div`);r.appendChild(a);let o=e.defaultView?.location.host??``;return oe(Y(Oi,{host:window.tawLens?.host||o,initial:ai(),env:Xi(e,n)}),a),n}typeof document<`u`&&(document.readyState===`loading`?document.addEventListener(`DOMContentLoaded`,()=>Zi(),{once:!0}):Zi());
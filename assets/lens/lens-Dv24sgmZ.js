var e,t,n,r,i,a,o,s,c,l,u={},d=[],f=/^m(i|n|o|s|text|space)$/,p=Array.isArray,m=d.slice,h=Object.assign;function g(e){e&&e.parentNode&&e.remove()}function _(n,r,i,a,o){var s={type:n,props:r,key:i,ref:a,__k:null,__:null,__b:0,__e:null,__c:null,constructor:void 0,__v:o||++t,__i:-1,__u:0};return!o&&e.vnode&&e.vnode(s),s}function v(e){return e.children}function y(e,t){this.props=e,this.context=t,this.__g=0}function b(e,t){if(t==null)return e.__?b(e.__,e.__i+1):null;for(var n;t<e.__k.length;t++)if((n=e.__k[t])&&n.__e)return n.__e;return typeof e.type!=`function`||e.props.__P?null:b(e)}function x(e){if((e=e.__)&&e.__c&&!e.props.__P)return e.__e=null,e.__k.some(function(t){return t&&(e.__e=t.__e)}),x(e)}function S(t){(8&t.__g||!(t.__g|=8)||!n.push(t)||i++)&&r==e.debounceRendering||((r=e.debounceRendering)||queueMicrotask)(C)}function C(){var t,r,o,s,c,l,u,d,f;try{for(r=1;n.length;)n.length>r&&n.sort(a),t=n.shift(),r=n.length,8&t.__g&&(s=void 0,c=void 0,l=(c=(o=t).__v).__e,u=[],d=[],(f=o.__P)&&((s=h({constructor:void 0},c)).__v=c.__v+1,e.vnode&&e.vnode(s),j(f,s,c,o.__n,f.namespaceURI,32&c.__u?[l]:null,u,l||b(c),32&c.__u,d),s.__v=c.__v,s.__.__k[s.__i]=s,M(u,s,d),c.__=c.__e=null,s.__e!=l&&x(s)))}finally{n.length=i=0}}function w(e,t,n,r,i,a,o,s,c,l,f){var p,m,h,g,_,v,y=r.__k||d,b=t.length;for(c=T(n,t,y,c,b),p=0;p<b;p++)(h=n.__k[p])!=null&&(m=~h.__i&&y[h.__i]||u,h.__i=p,v=j(e,h,m,i,a,o,s,c,l,f),g=h.__e,(m.ref!=h.ref||8&m.__u)&&(m.ref&&N(m.ref,null,h,m),h.ref&&f.push(h.ref,h.__c||g,h)),_||=g,4&h.__u?(c=E(h,c,e,!m.__v),m.__e&&(m.__e=null)):typeof h.type==`function`&&v!==void 0?c=v:g&&(c=g.nextSibling),h.__u&=-7);return n.__e=_,c}function T(e,t,n,r,i){var a,o,s,c,l,u,d,f,m,h,g=n.length,y=g,x=0,S=!1,C=e.__k=Array(i);for(a=0;a<i;a++)(o=t[a])!=null&&typeof o!=`boolean`&&typeof o!=`function`?(typeof o!=`object`||o.constructor==String?o=C[a]=_(null,o):p(o)?o=C[a]=_(v,{children:o}):o.constructor===void 0&&o.__b?o=C[a]=_(o.type,o.props,o.key,o.ref,o.__v):C[a]=o,c=a+x,o.__=e,o.__b=e.__b+1,s=null,~(l=o.__i=D(o,n,c,y))&&(y--,(s=n[l])&&(s.__u|=2)),s&&s.__v?(o.__u|=2,l==c-1?x--:l==c+1?x++:l!=c&&(l>c?x--:x++,S=!0)):(~l||(i>g?x--:i<g&&x++),typeof o.type!=`function`&&(o.__u|=4))):C[a]=null;if(S){for(u=[],d=[],a=0;a<i;a++)if((o=C[a])&&2&o.__u){for(f=0,m=u.length;f<m;)u[h=f+m>>1]<o.__i?f=h+1:m=h;u[f]=o.__i,d[a]=f+1}for(x=u.length;a--;)d[a]&&(d[a]==x?x--:C[a].__u|=4)}if(y)for(a=0;a<g;a++)!(s=n[a])||2&s.__u||(s.__e==r&&(r=b(s)),ne(s,s));return r}function E(e,t,n,r){var i,a,o;if(typeof e.type==`function`){if(e.props.__P)return t;if(i=e.__k)for(a=0;a<i.length;a++)i[a]&&(i[a].__=e,t=E(i[a],t,n,!1));return t}for(t&&!t.parentNode&&(t=b(e))&&!t.parentNode&&(t=null),o=t;o&&o.nodeType==8;)o=o.nextSibling;for(e.__e!=o&&(!r&&n.moveBefore&&e.__e.parentNode?n.moveBefore(e.__e,t):n.insertBefore(e.__e,t||null)),t=e.__e;(t&&=t.nextSibling)&&t.nodeType==8;);return t}function D(e,t,n,r){var i,a,o,s=e.key,c=e.type,l=t[n],u=l&&!(2&l.__u);if(l===null&&s==null||u&&s==l.key&&c==l.type)return n;if(r>+!!u){for(i=n-1,a=n+1;i>=0||a<t.length;)if((l=t[o=i>=0?i--:a++])&&!(2&l.__u)&&s==l.key&&c==l.type)return o}return-1}function O(e,t,n){n??=``,t[0]==`-`?e.setProperty(t,n):e[t]=n}function k(e,t,n,r,i){var a,o;n:if(t==`style`){if(typeof n==`string`)e.style.cssText=n;else{if(typeof r==`string`&&(e.style.cssText=r=``),r)for(t in r)n&&t in n||O(e.style,t,``);if(n)for(t in n)r&&n[t]==r[t]||O(e.style,t,n[t])}}else if(t[0]==`o`&&t[1]==`n`)(e.__e||={})[t]=n,n&&r||(o=l[t]||(l[t]=A(t)),(e.__a||={})[t]=c,a=t!=(t=t.replace(s,`$1`)),(t=t.slice(2))[0]<`a`&&(t=t.toLowerCase()),n?e.addEventListener(t,o,a):e.removeEventListener(t,o,a));else{if(i==`http://www.w3.org/2000/svg`)t=t.replace(/xlink(H|:h)/,`h`).replace(/sName$/,`s`);else if(t!=`width`&&t!=`height`&&t!=`href`&&t!=`list`&&t!=`form`&&t!=`tabIndex`&&t!=`download`&&t!=`rowSpan`&&t!=`colSpan`&&t!=`role`&&t!=`popover`&&t in e)try{e[t]=n??``;break n}catch{}typeof n==`function`||(n==null||!1===n&&t[4]!=`-`?e.removeAttribute(t):e.setAttribute(t,t==`popover`&&n==1?``:n))}}function A(t){return function(n){if(this.__e){var r=this.__e[t];if(n[o]==null)n[o]=c++;else if(n[o]<this.__a[t])return;return r(e.event?e.event(n):n)}}}function j(t,n,r,i,a,o,s,c,l,u){var f,m,_,x,S,C,T,E,D,O,k,A,j,M,N,re,F,I,L,R,z=n.type;if(n.constructor!==void 0)return null;if(128&r.__u&&(l=32&r.__u,f=r.__c.__z)){if(n.__u|=l,m=o=[],f.nodeType==8)for(_=1,x=f.nextSibling;x;x=x.nextSibling){if(x.nodeType==8){if(x.data.startsWith(`$s`))_++;else if(x.data.startsWith(`/$s`)&&!--_)break}o.push(x)}else o.push(f);c=o[0]}(f=e.__b)&&f(n);n:if(typeof z==`function`){S=s.length;try{if(O=n.props,k=(f=z.prototype)&&f.render,A=(f=z.contextType)&&i[f.__c],j=f?A?A.props.value:f.__:i,r.__c?2&(C=n.__c=r.__c).__g&&(C.__g|=1):(k?n.__c=C=new z(O,j):(n.__c=C=new y(O,j),C.constructor=z,C.render=P),A&&A.sub(C),C.state||(C.state={}),C.__n=i,C.__g|=8,C.__h=[],C.__k=[]),k&&(C.__s||(C.__s=C.state),z.getDerivedStateFromProps&&(C.__s==C.state&&(C.__s=h({},C.__s)),h(C.__s,z.getDerivedStateFromProps(O,C.__s)))),T=C.props,E=C.state,C.__v=n,r.__c){if(k&&!z.getDerivedStateFromProps&&O!==T&&C.componentWillReceiveProps&&C.componentWillReceiveProps(O,j),n.__v==r.__v&&!(8&C.__g)||!(4&C.__g)&&C.shouldComponentUpdate&&!1===C.shouldComponentUpdate(O,C.__s,j)){n.__v!=r.__v&&(C.props=O,C.state=C.__s,C.__g&=-9),n.__e=r.__e,n.__k=r.__k,n.__k.some(function(e){e&&(e.__=n)}),d.push.apply(C.__h,C.__k),C.__k=[],C.__h.length&&s.push(C),c=b(r);break n}C.componentWillUpdate&&C.componentWillUpdate(O,C.__s,j),k&&C.componentDidUpdate&&C.__h.push(function(){C.componentDidUpdate(T,E,D)})}else k&&!z.getDerivedStateFromProps&&C.componentWillMount&&C.componentWillMount(),k&&C.componentDidMount&&C.__h.push(C.componentDidMount);if(C.context=j,C.props=O,C.__P=t,C.__g&=-5,M=e.__r,N=0,k)C.state=C.__s,C.__g&=-9,M&&M(n),f=C.render(C.props,C.state,C.context),d.push.apply(C.__h,C.__k),C.__k=[];else do C.__g&=-9,M&&M(n),f=C.render(C.props,C.state,C.context),C.state=C.__s;while(8&C.__g&&++N<25);C.state=C.__s,C.getChildContext&&(i=h({},i,C.getChildContext())),k&&r.__c&&C.getSnapshotBeforeUpdate&&(D=C.getSnapshotBeforeUpdate(T,E)),re=f&&f.type===v&&f.key==null?f.props.children:f,O.__P&&(f=c,a=(t=O.__P).namespaceURI,l=o=null,r.props&&r.props.__P!=t&&(r.__k.some(function(e){e&&ne(e,e)}),r.__k=null),c=r.__k?b(r,0):null),c=w(t,p(re)?re:[re],n,r,i,a,o,s,c,l,u),O.__P&&(n.__e=null,c=f),n.__u&=-161,128&r.__u&&(C.__z=null),m&&m.some(g),C.__h.length&&s.push(C),1&C.__g&&(C.__g&=-4)}catch(t){if(s.length=S,n.__v=null,l||o){if(t.then){if(F=0,n.__u|=l?160:128,o){for(~(L=o.indexOf(c||void 0))||(L=o.length);(R=o[L-1])&&R.nodeType==8;)L--;for(;L<o.length;L++)if(R=o[L]){if(o[L]=null,R.nodeType==8){if(R.data.startsWith(`$s`))F++||(I=R);else if(F&&R.data.startsWith(`/$s`)&&!--F){c=R;break}}else if(!F)break}}if(!I){for(;c&&c.nodeType==8&&c.nextSibling;)c=c.nextSibling;I=c}n.__c.__z||(n.__c.__z=I),n.__e=c}else o&&o.some(g)}else n.__e=r.__e;n.__k||=r.__k||[],t.then||ee(n),e.__e(t,n,r)}}else c=n.__e=te(r.__e,n,r,i,a,o,s,l,u,t);return(f=e.diffed)&&f(n),128&n.__u?void 0:c}function ee(e){e&&(e.__c&&(e.__c.__g|=4),e.__k&&e.__k.some(ee))}function M(t,n,r){for(var i=0;i<r.length;)N(r[i++],r[i++],r[i++]);e.__c&&e.__c(n,t),t.some(function(n){try{t=n.__h,n.__h=[],t.some(function(e){e.call(n)})}catch(t){e.__e(t,n.__v)}})}function te(t,n,r,i,a,o,s,c,l,d){var h,_,v,y,x,S,C,T,E,D=r.props||u,O=n.props,A=n.type;if(A==`svg`?a=`http://www.w3.org/2000/svg`:A==`math`?a=`http://www.w3.org/1998/Math/MathML`:a||=`http://www.w3.org/1999/xhtml`,o){for(h=0;h<o.length;h++)if((x=o[h])&&(A?x.localName==A:x.nodeType==3)){t=x,o[h]=null;break}}if(!t){if(T=d.ownerDocument||document,!A)return T.createTextNode(O);t=T.createElementNS(a,A,O.is&&O),c&&=(e.__m&&e.__m(n,o),!1),o=null}if(A){if(d=A==`template`?t.content:t,o=A==`textarea`&&O.defaultValue!=null?null:o&&m.call(d.childNodes),!c&&o)for(D={},h=0;h<t.attributes.length;h++)D[(x=t.attributes[h]).name]=x.value;for(h in D)x=D[h],h==`dangerouslySetInnerHTML`?v=x:h==`children`||h in O||h==`value`&&`defaultValue`in O||h==`checked`&&`defaultChecked`in O||k(t,h,null,x,a);for(h in E=1&r.__u,O)x=O[h],h==`children`?y=x:h==`dangerouslySetInnerHTML`?_=x:h==`value`?S=x:h==`checked`?C=x:c&&typeof x!=`function`||!(D[h]!==x||E&&x!=null)||k(t,h,x,D[h],a);_?(c||v&&(_.__html==v.__html||_.__html==t.innerHTML)||(t.innerHTML=_.__html),n.__k=[]):(v&&(t.textContent=``),(A==`foreignObject`||a==`http://www.w3.org/1998/Math/MathML`&&f.test(A))&&(a=`http://www.w3.org/1999/xhtml`),w(d,p(y)?y:[y],n,r,i,a,o,s,o?o[0]:r.__k&&b(r,0),c,l),o&&o.some(g)),c&&A!=`textarea`||(h=`value`,A==`progress`&&S==null?t.removeAttribute(h):S==null||S===t[h]&&(A!=`progress`||S)||k(t,h,S,D[h],a),h=`checked`,C!=null&&C!=t[h]&&k(t,h,C,D[h],a))}else D===O||c&&t.data==O||(t.data=O);return t}function N(t,n,r,i){try{typeof t==`function`?n?n.__x=t(n)||1:(i&&=i.__c||i.__e)&&(n=i.__x)&&(i.__x=null,typeof n==`function`?n():t(null)):t.current=n}catch(t){e.__e(t,r)}}function ne(t,n,r){var i,a;if(e.unmount&&e.unmount(t),!(i=t.ref)||i.current&&i.current!=t.__e||N(i,null,n,t),i=t.__c){if(i.componentWillUnmount)try{i.componentWillUnmount()}catch(t){e.__e(t,n)}i.__P=i.__n=null}if(i=t.__k)for(a=0;a<i.length;a++)i[a]&&ne(i[a],n,typeof t.type!=`function`||r&&!t.props.__P);(i=t.__e)&&(r||g(i),i.__e&&(i.__e=null)),t.__e=t.__c=t.__=null}function P(e,t,n){return this.constructor(e,n)}function re(t,n){var r,i,a,o;e.__&&e.__(t,n),n.nodeType==9&&(n=n.documentElement),i=(r=t&&32&t.__u)?null:n.__k,n.__k=_(v,{children:[t]}),a=[],o=[],j(n,n.__k,i||u,u,n.namespaceURI,i?null:n.firstChild?m.call(n.childNodes):null,a,i?i.__e:n.firstChild,r,o),M(a,n.__k,o),n.__k.props.children=null}e={__e:function(e,t,n,r){for(var a,o,s;t=t.__;)if((a=t.__c)&&!(1&a.__g)){a.__g|=4;try{if((o=a.constructor)&&o.getDerivedStateFromError&&(a.setState(o.getDerivedStateFromError(e)),s=8&a.__g),a.componentDidCatch&&(a.componentDidCatch(e,r||{}),s=8&a.__g),s)return void(a.__g|=2)}catch(t){e=t,s=0}}throw i=0,e}},t=0,y.prototype.setState=function(e,t){var n=this.__s;n&&n!=this.state||(n=this.__s=h({},this.state)),typeof e==`function`&&(e=e(h({},n),this.props)),e&&(h(n,e),this.__v&&(t&&this.__k.push(t),S(this)))},y.prototype.forceUpdate=function(e){this.__v&&(this.__g|=4,e&&this.__h.push(e),S(this))},y.prototype.render=v,n=[],i=0,a=function(e,t){return e.__v.__b-t.__v.__b},o=Symbol(),s=/(PointerCapture)$|Capture$/i,c=0,l={};var F=3e3,I=1500,L=()=>({fetch:(...e)=>window.fetch(...e),EventSource:window.EventSource,setTimeout:(e,t)=>window.setTimeout(e,t),clearTimeout:e=>window.clearTimeout(e),visible:()=>document.visibilityState!==`hidden`}),R=e=>{let t=e;return!!t&&t.running===!0&&typeof t.url==`string`&&/^http:\/\/127\.0\.0\.1:\d+$/.test(t.url)&&typeof t.token==`string`};function z(e,t,n=L()){let r=!1,i=null,a=null,o=null,s=null,c=e=>{e!==s&&(s=e,t.status(e))},l=e=>{r||(i=n.setTimeout(()=>void u(),e))};async function u(){if(i=null,r)return;if(!n.visible()){l(F);return}let s=null;try{let t=await n.fetch(e.bridge,{credentials:`same-origin`,cache:`no-store`,headers:{"X-WP-Nonce":e.nonce}});s=t.ok?await t.json():null}catch{s=null}if(r)return;if(!R(s)){c(`off`),l(F);return}if(s.protocol!==e.protocol){c(`outdated`),l(F*4);return}c(`connecting`),o={url:s.url,token:s.token};let u=new n.EventSource(`${s.url}/events?token=${encodeURIComponent(s.token)}`);a=u,u.onmessage=e=>{let n;try{n=JSON.parse(e.data)}catch{return}n.type===`hello`&&c(`connected`),t.event(n)},u.onerror=()=>{u.close(),a===u&&(a=null,o=null),c(`off`),l(I)}}return u(),{async send(e,t){if(!o)return{ok:!1,status:0};try{let r=await n.fetch(`${o.url}${e}`,{method:`POST`,headers:{"content-type":`application/json`,"x-lens-token":o.token},body:JSON.stringify(t)});return{ok:r.ok,status:r.status}}catch{return{ok:!1,status:0}}},close(){r=!0,i!==null&&n.clearTimeout(i),a?.close(),a=null}}}var B,V,ie,ae,oe=Object.is,se=0,ce=[],le=[],H=e,ue=H.__b,de=H.__r,fe=H.diffed,pe=H.__c,me=H.unmount,he=H.__;function U(e,t){H.__h&&H.__h(V,e,se||t),se=0;var n=V.__H||(V.__H={__:[],__h:[]});return e>=n.__.length&&n.__.push({}),n.__[e]}function W(e){return se=1,G(Ee,e)}function G(e,t,n){var r=U(B++,2);if(r.t=e,!r.__c&&(r.__=[n?n(t):Ee(void 0,t),function(e){var t=r.__N?r.__N[0]:r.__[0],n=r.t(t,e);oe(t,n)||(r.__N=[n,r.__[1]],r.__c.setState({}))}],r.__c=V,!V.__f)){V.__f=!0;var i=V.shouldComponentUpdate;V.shouldComponentUpdate=function(e,t,n){var r=this.__H;if(!r)return!0;var a=!1,o=this.props!=e;if(r.__.some(function(e){e.__N&&(a=!0,oe(e.__[0],e.__N[0])||(o=!0))}),i){var s=i.call(this,e,t,n);return a?s||o:s}return!a||o}}return r.__}function K(e,t){var n=U(B++,3);!H.__s&&Te(n.__H,t)&&(n.__P=!0,n.__=e,n.u=t,V.__H.__h.push(n))}function ge(e,t){var n=U(B++,4);!H.__s&&Te(n.__H,t)&&(n.__P=!1,n.__=e,n.u=t,V.__h.push(n))}function q(e){return se=5,_e(function(){return{current:e}},[])}function _e(e,t){var n=U(B++,7);return Te(n.__H,t)&&(n.__=e(),n.__H=t),n.__}function ve(e,t){return se=8,_e(function(){return e},t)}function ye(){var e;do{for(;e=le.shift();)try{Ce(e)}catch(t){H.__e(t,{__:(e=e.__P)&&e.__v})}for(;e=ce.shift();){var t=e.__H;if(e.__P&&t)try{t.__h.some(Ce),t.__h.some(we),t.__h=[]}catch(n){t.__h=[],H.__e(n,e.__v)}}}while(le.length)}H.__b=function(e){V=null,ue&&ue(e)},H.__=function(e,t){e&&t.__k&&t.__k.__m&&(e.__m=t.__k.__m),he&&he(e,t)},H.__r=function(e){de&&de(e),B=0;var t=(V=e.__c).__H;t&&(ie==V?V.__h=[]:(t.__h.some(Ce),t.__h.some(we),B=0),t.__h=[],t.__.some(function(e){e.__N&&(e.__=e.__N),e.u=e.__N=void 0})),ie=V},H.diffed=function(e){fe&&fe(e);var t=e.__c;t&&t.__H&&(t.__H.__h.length&&Se(ce.push(t)),t.__H.__.some(function(e){e.u&&(e.__H=e.u)})),ie=V=null},H.__c=function(e,t){t.some(function(e){try{e.__h.some(Ce),e.__h=e.__h.filter(function(e){return!e.__||we(e)})}catch(n){t.some(function(e){e.__h&&=[]}),t=[],H.__e(n,e.__v)}}),pe&&pe(e,t)},H.unmount=function(e){me&&me(e);var t,n,r=e.__c;r&&r.__H&&(r.__H.__.some(function(r){try{if(r.__P&&r.__c){if(n===void 0){for(n=e.__;n&&(!n.__c||!n.__c.__P);)n=n.__;n&&=n.__c}r.__P=n,Se(le.push(r))}else Ce(r)}catch(e){t=e}}),r.__H=void 0,t&&H.__e(t,r.__v))};var be=typeof requestAnimationFrame==`function`;function xe(e){var t,n=function(){clearTimeout(r),be&&cancelAnimationFrame(t),setTimeout(e)},r=setTimeout(n,35);be&&(t=requestAnimationFrame(n))}function Se(e){e!=1&&ae==H.requestAnimationFrame||((ae=H.requestAnimationFrame)||xe)(ye)}function Ce(e){var t=V,n=e.__c;typeof n==`function`&&(e.__c=void 0,n()),V=t}function we(e){var t=V;e.__c=e.__(),V=t}function Te(e,t){return!e||e.length!=t.length||t.some(function(t,n){return!oe(t,e[n])})}function Ee(e,t){return typeof t==`function`?t(e):t}var De=()=>window.wp?.i18n,J=(e,t)=>De()?.__(e,t)??e;function Oe(e){if(e.length===0)return``;let t=e.map(e=>e.split(`/`).slice(0,-1)),n=t[0],r=0;for(;r<n.length&&t.every(e=>e[r]===n[r]);)r++;return r>0?`${n.slice(0,r).join(`/`)}/`:``}var ke=e=>e.some(e=>e.state===`running`)?`running`:e.some(e=>e.state===`failed`)?`failed`:`done`,Ae=e=>e===`read`||e===`search`||e===`plan`;function je(e){let t=[];for(let n of e){let e=t[t.length-1];e&&Ae(n.kind)&&e[0].kind===n.kind?e.push(n):t.push([n])}return t}function Me(e){let t=ke(e),n=t===`running`,r=t===`failed`,[i]=e;if(r&&e.length===1)switch(i.kind){case`read`:return{text:J(`Couldn’t read %s`,`taw-core`),code:i.target,state:t};case`edit`:return{text:J(`Didn’t edit %s`,`taw-core`),code:i.target,state:t};case`write`:return{text:J(`Didn’t write %s`,`taw-core`),code:i.target,state:t};case`run`:return{text:J(`Didn’t run %s`,`taw-core`),code:i.target,state:t}}switch(i.kind){case`read`:{let r=[...new Set(e.map(e=>e.target).filter(Boolean))];if(r.length<=1)return{text:J(n?`Reading %s`:`Read %s`,`taw-core`),code:r[0]??``,state:t};let i=Oe(r),a=String(r.length);return i?{text:J(n?`Reading %d files in %s`:`Read %d files in %s`,`taw-core`).replace(`%d`,a),code:i,state:t}:{text:J(n?`Reading %d files`:`Read %d files`,`taw-core`).replace(`%d`,a),code:``,state:t}}case`search`:return e.length===1?{text:J(n?`Searching for %s`:`Searched for %s`,`taw-core`),code:i.target,state:t}:{text:J(`Searched the theme %d times`,`taw-core`).replace(`%d`,String(e.length)),code:``,state:t};case`edit`:return{text:J(n?`Editing %s`:`Edited %s`,`taw-core`),code:i.target,state:t};case`write`:return{text:J(n?`Writing %s`:`Wrote %s`,`taw-core`),code:i.target,state:t};case`run`:return{text:J(n?`Running %s`:`Ran %s`,`taw-core`),code:i.target,state:t};case`skill`:return{text:J(`Used the %s skill`,`taw-core`),code:i.target,state:t};case`plan`:return{text:J(`Updated its plan`,`taw-core`),code:``,state:t};default:return{text:J(`Used %s`,`taw-core`),code:i.name,state:t}}}var Ne=e=>je(e).map(Me),Pe=e=>e.target?`${e.name} ${e.target}`:e.name,Fe={turns:[],working:!1,busy:null,takenOver:!1,session:null},Ie=`connection`,Y=(e,t,n)=>{let r=e.turns.findIndex(e=>e.turn===t);if(r<0)return e;let i=e.turns.slice();return i[r]=n(i[r]),{...e,turns:i}},Le=e=>e.items[e.items.length-1],Re=(e,t,n)=>{for(let r of e.turns)if(r.items.some(e=>e.type===`approval`&&e.id===t))return Y(e,r.turn,e=>({...e,items:e.items.map(e=>e.type===`approval`&&e.id===t?{...e,state:n}:e)}));return e};function ze(e,t){switch(t.type){case`hello`:return{...t.history.reduce(ze,{...Fe,session:t.session}),working:t.working,busy:t.busy,takenOver:t.takenOver,session:t.session};case`turn`:return{...e,working:!0,turns:[...e.turns,{turn:t.turn,text:t.text,chips:t.chips??[],items:[]}]};case`text`:return Y(e,t.turn,e=>{let n=Le(e),r=n?.type===`text`?[...e.items.slice(0,-1),{type:`text`,text:n.text+t.delta}]:[...e.items,{type:`text`,text:t.delta}];return{...e,items:r}});case`tool`:return Y(e,t.turn,e=>{let n={id:t.id,name:t.name,kind:t.kind,target:t.target,state:`running`},r=Le(e),i=r?.type===`steps`?[...e.items.slice(0,-1),{type:`steps`,steps:[...r.steps,n]}]:[...e.items,{type:`steps`,steps:[n]}];return{...e,items:i}});case`tool-done`:return Y(e,t.turn,e=>({...e,items:e.items.map(e=>e.type===`steps`?{...e,steps:e.steps.map(e=>e.id===t.id?{...e,state:t.ok?`done`:`failed`}:e)}:e)}));case`approval`:return Y(e,t.turn,e=>({...e,items:[...e.items,{type:`approval`,id:t.id,tool:t.tool,kind:t.kind,target:t.target,input:t.input??{},state:`pending`}]}));case`approval-done`:return Re(e,t.id,t.allow?`applied`:`skipped`);case`done`:return Y({...e,working:!1},t.turn,e=>({...e,items:e.items.map(e=>e.type===`steps`?{...e,steps:e.steps.map(e=>e.state===`running`?{...e,state:`failed`}:e)}:e.type===`approval`&&e.state!==`applied`?{...e,state:e.state===`pending`||e.state===`sending`?`skipped`:e.state}:e),outcome:t.outcome,message:t.message,detail:t.detail,changed:t.changed??[]}));case`busy`:return{...e,busy:t.busy,takenOver:t.takenOver};default:return e}}function Be(e){let t=e.turns.find(e=>e.outcome===void 0);return!e.working||!t?{...e,working:!1}:ze(e,{type:`done`,turn:t.turn,outcome:`error`,message:Ie,changed:[]})}var Ve=(e,t,n)=>Re(e,t,n),He=400,Ue=e=>e===``?[]:e.replace(/\n$/,``).split(`
`);function We(e,t){let n=Ue(e),r=Ue(t);if(n.length>He||r.length>He)return[...n.map(e=>({kind:`-`,text:e})),...r.map(e=>({kind:`+`,text:e}))];let i=Array.from({length:n.length+1},()=>Array(r.length+1).fill(0));for(let e=n.length-1;e>=0;e--)for(let t=r.length-1;t>=0;t--)i[e][t]=n[e]===r[t]?i[e+1][t+1]+1:Math.max(i[e+1][t],i[e][t+1]);let a=[],o=0,s=0;for(;o<n.length&&s<r.length;)n[o]===r[s]?(a.push({kind:` `,text:n[o]}),o++,s++):i[o+1][s]>=i[o][s+1]?a.push({kind:`-`,text:n[o++]}):a.push({kind:`+`,text:r[s++]});for(;o<n.length;)a.push({kind:`-`,text:n[o++]});for(;s<r.length;)a.push({kind:`+`,text:r[s++]});return a}function Ge(e){return e.edits?e.edits.flatMap((e,t)=>[...t>0?[{kind:` `,text:`…`}]:[],...We(e.old,e.new)]):typeof e.content==`string`?Ue(e.content).map(e=>({kind:`+`,text:e})):[]}var Ke=0;Array.isArray;function X(t,n,r,i,a,o){n||={};var s,c,l=n;if(`ref`in l&&typeof t!=`function`)for(c in l={},n)c==`ref`?s=n[c]:l[c]=n[c];var u={type:t,props:l,key:r,ref:s,__k:null,__:null,__b:0,__e:null,__c:null,constructor:void 0,__v:--Ke,__i:-1,__u:0};return(a||o)&&(u.__source=a,u.__self=o),e.vnode&&e.vnode(u),u}var Z=({size:e=16,width:t=1.8,children:n})=>X(`svg`,{width:e,height:e,viewBox:`0 0 24 24`,fill:`none`,stroke:`currentColor`,"stroke-width":t,"stroke-linecap":`round`,"stroke-linejoin":`round`,"aria-hidden":`true`,focusable:`false`,children:n}),qe=e=>X(Z,{...e,children:[X(`circle`,{cx:`11`,cy:`11`,r:`6.5`}),X(`path`,{d:`M16 16l4.5 4.5`})]}),Je=e=>X(Z,{...e,children:X(`path`,{d:`M5 3l14 7-6 2-2 6z`})}),Ye=e=>X(Z,{...e,children:X(`path`,{d:`M4 5h6M8 12h6M8 19h6M6 5v14M6 12h2M6 19h2`})}),Xe=e=>X(Z,{...e,children:[X(`rect`,{x:`4`,y:`6`,width:`12`,height:`12`,rx:`2`}),X(`path`,{d:`M13 3h8v8M21 3l-8 8`})]}),Ze=e=>X(Z,{...e,children:[X(`rect`,{x:`3`,y:`4`,width:`18`,height:`16`,rx:`2`}),X(`path`,{d:`M15 4v16`})]}),Qe=({side:e,...t})=>X(Z,{...t,children:X(`path`,{d:e===`right`?`M9 6l6 6-6 6`:`M15 6l-6 6 6 6`})}),$e=e=>X(Z,{...e,children:X(`path`,{d:`M6 12h12`})}),et=e=>X(Z,{...e,width:2,children:X(`path`,{d:`M12 19V5M6 11l6-6 6 6`})}),tt=e=>X(Z,{...e,children:[X(`circle`,{cx:`12`,cy:`12`,r:`8`}),X(`path`,{d:`M12 4a8 8 0 0 1 0 16z`,fill:`currentColor`})]}),nt=e=>X(Z,{...e,children:[X(`circle`,{cx:`12`,cy:`12`,r:`4`}),X(`path`,{d:`M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4`})]}),rt=e=>X(Z,{...e,children:X(`path`,{d:`M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z`})}),it=({size:e=14})=>X(`svg`,{width:e,height:e,viewBox:`0 0 24 24`,fill:`currentColor`,"aria-hidden":`true`,focusable:`false`,children:[X(`circle`,{cx:`9`,cy:`6`,r:`1.6`}),X(`circle`,{cx:`15`,cy:`6`,r:`1.6`}),X(`circle`,{cx:`9`,cy:`12`,r:`1.6`}),X(`circle`,{cx:`15`,cy:`12`,r:`1.6`}),X(`circle`,{cx:`9`,cy:`18`,r:`1.6`}),X(`circle`,{cx:`15`,cy:`18`,r:`1.6`})]}),at=({size:e=14})=>X(Z,{size:e,width:2,children:X(`path`,{d:`M6 6l12 12M18 6L6 18`})}),ot=({size:e=12})=>X(Z,{size:e,width:2,children:X(`path`,{d:`M6 9l6 6 6-6`})}),st=({size:e=12})=>X(Z,{size:e,width:2,children:X(`path`,{d:`M9 6l6 6-6 6`})}),ct=({size:e=14})=>X(Z,{size:e,children:[X(`rect`,{x:`5`,y:`3`,width:`14`,height:`18`,rx:`2`}),X(`path`,{d:`M9 8h6M9 12h6`})]}),lt=({size:e=14})=>X(Z,{size:e,children:[X(`rect`,{x:`4`,y:`4`,width:`16`,height:`16`,rx:`2`}),X(`path`,{d:`M4 9h16`})]}),ut=({size:e=14})=>X(Z,{size:e,children:[X(`rect`,{x:`4`,y:`4`,width:`7`,height:`7`,rx:`1`}),X(`rect`,{x:`13`,y:`4`,width:`7`,height:`7`,rx:`1`}),X(`rect`,{x:`4`,y:`13`,width:`16`,height:`7`,rx:`1`})]}),dt=({size:e=14})=>X(Z,{size:e,children:X(`path`,{d:`M5 7h14M5 12h10M5 17h7`})}),ft=({size:e=14})=>X(Z,{size:e,children:X(`path`,{d:`M6 6h12M12 6v12`})}),pt=({size:e=14})=>X(Z,{size:e,width:2,children:X(`path`,{d:`M5 12.5l4.5 4.5L19 7.5`})}),mt=({size:e=14})=>X(Z,{size:e,width:2,children:X(`path`,{d:`M7 7l10 10M17 7L7 17`})}),ht=({size:e=14})=>X(`svg`,{class:`spinner`,width:e,height:e,viewBox:`0 0 24 24`,fill:`none`,"aria-hidden":`true`,focusable:`false`,children:X(`path`,{d:`M12 3.5a8.5 8.5 0 1 1-8.5 8.5`,stroke:`currentColor`,"stroke-width":`2`,"stroke-linecap":`round`})}),gt=({size:e=16})=>X(Z,{size:e,children:[X(`circle`,{cx:`12`,cy:`12`,r:`8.5`}),X(`path`,{d:`M12 7.5v5.5M12 16.4v.1`})]}),_t=({size:e=22})=>X(Z,{size:e,children:[X(`path`,{d:`M9.5 8H8a4 4 0 0 0 0 8h1.5M14.5 8H16a4 4 0 0 1 0 8h-1.5`}),X(`path`,{d:`M10 12h.01M14 12h.01`})]}),vt=({size:e=10})=>X(`svg`,{width:e,height:e,viewBox:`0 0 10 10`,fill:`currentColor`,"aria-hidden":`true`,focusable:`false`,children:X(`rect`,{width:`10`,height:`10`,rx:`1.5`})});function yt(e){let t=[],n=e.replace(/\r\n?/g,`
`).split(`
`),r=[],i=()=>{r.length&&(t.push({kind:`p`,text:r.join(`
`)}),r=[])};for(let e=0;e<n.length;e++){let a=n[e];if(/^\s*```/.test(a)){i();let r=[];for(;++e<n.length&&!/^\s*```/.test(n[e]);)r.push(n[e]);t.push({kind:`code`,text:r.join(`
`)});continue}let o=/^\s*[-*+]\s+(.*)$/.exec(a),s=/^\s*\d+[.)]\s+(.*)$/.exec(a);if(o||s){i();let e=o?`ul`:`ol`,n=t[t.length-1],r=(o??s)[1];n&&n.kind===e?n.items.push(r):t.push({kind:e,items:[r]});continue}let c=/^#{1,6}\s+(.*)$/.exec(a);if(c){i(),t.push({kind:`h`,text:c[1]});continue}if(a.trim()===``){i();continue}let l=t[t.length-1];if(r.length===0&&/^\s{2,}\S/.test(a)&&l&&(l.kind===`ul`||l.kind===`ol`)){l.items[l.items.length-1]+=` ${a.trim()}`;continue}r.push(a)}return i(),t}var bt=/^https?:\/\//i;function xt(e){let t=[],n=/(`+)([^`]+?)\1|\*\*([^*]+)\*\*|\*([^*\s][^*]*?)\*|(?<![\w])_([^_\s][^_]*?)_(?![\w])|\[([^\]]+)\]\(([^)\s]+)\)/g,r=0,i,a=0;for(;i=n.exec(e);){if(i.index>r&&t.push(e.slice(r,i.index)),i[2]!==void 0)t.push(X(`code`,{children:i[2]},a++));else if(i[3]!==void 0)t.push(X(`strong`,{children:xt(i[3])},a++));else if(i[4]!==void 0||i[5]!==void 0)t.push(X(`em`,{children:xt(i[4]??i[5])},a++));else{let e=i[7];t.push(bt.test(e)?X(`a`,{href:e,target:`_blank`,rel:`noopener noreferrer`,children:i[6]},a++):i[6])}r=i.index+i[0].length}return r<e.length&&t.push(e.slice(r)),t}var St=e=>e.split(`
`).flatMap((e,t)=>t===0?xt(e):[X(`br`,{},`br${t}`),...xt(e)]);function Ct({source:e}){return X(`div`,{class:`md`,children:yt(e).map((e,t)=>{switch(e.kind){case`code`:return X(`pre`,{children:X(`code`,{children:e.text})},t);case`h`:return X(`p`,{children:X(`strong`,{children:xt(e.text)})},t);case`ul`:return X(`ul`,{children:e.items.map((e,t)=>X(`li`,{children:xt(e)},t))},t);case`ol`:return X(`ol`,{children:e.items.map((e,t)=>X(`li`,{children:xt(e)},t))},t);default:return X(`p`,{children:St(e.text)},t)}})})}var wt=`taw lens`;function Tt({text:e,code:t}){let[n,r=``]=e.split(`%s`);return X(`span`,{class:`step-text`,children:[n,t&&X(`code`,{children:t}),r]})}var Et=({state:e})=>e===`running`?X(`span`,{class:`step-icon`,children:[X(ht,{size:13}),X(`span`,{class:`sr-only`,children:J(`In progress`,`taw-core`)})]}):e===`failed`?X(`span`,{class:`step-icon`,children:[X(mt,{size:13}),X(`span`,{class:`sr-only`,children:J(`Didn’t finish`,`taw-core`)})]}):X(`span`,{class:`step-icon`,children:[X(pt,{size:13}),X(`span`,{class:`sr-only`,children:J(`Done`,`taw-core`)})]});function Dt({steps:e}){let[t,n]=W(!1);return X(`div`,{class:`activity`,children:[Ne(e).map((e,r)=>X(`div`,{class:`step`,children:[X(Et,{state:e.state}),X(Tt,{text:e.text,code:e.code}),r===0&&X(`button`,{type:`button`,class:`text-button step-details`,"aria-expanded":t,onClick:()=>n(!t),children:J(t?`Hide details`:`Details`,`taw-core`)})]},r)),t&&X(`ul`,{class:`step-list`,children:e.map(e=>X(`li`,{children:[X(Et,{state:e.state}),X(`code`,{children:Pe(e)})]},e.id))})]})}function Ot(e){switch(e.kind){case`edit`:return J(`Edit 1 file`,`taw-core`);case`write`:return J(`Write 1 file`,`taw-core`);case`run`:return J(`Run a command`,`taw-core`);default:return J(`Use %s`,`taw-core`).replace(`%s`,e.tool)}}function kt({item:e,onAnswer:t}){let n=Ot(e),r=`approval-${e.id}`,i=e.input.file??``;if(e.state===`applied`||e.state===`skipped`)return X(`div`,{class:`approval-done`,children:[X(`span`,{class:`step-icon`,children:e.state===`applied`?X(pt,{size:13}):X(mt,{size:13})}),X(Tt,{text:e.state===`applied`?J(`Applied: %s`,`taw-core`):J(`Skipped: %s`,`taw-core`),code:i||e.target||e.tool})]});let a=Ge(e.input),o=a.slice(0,200),s=e.kind===`edit`||e.kind===`write`;return X(`div`,{class:`approval`,role:`group`,"aria-labelledby":r,children:[X(`div`,{class:`approval-head`,children:[X(`span`,{class:`approval-dot`,"aria-hidden":`true`}),X(`span`,{class:`approval-title`,id:r,children:n}),i&&X(`span`,{class:`approval-file`,children:i})]}),a.length>0?X(`pre`,{class:`diff`,"aria-label":J(`Proposed change`,`taw-core`),children:[o.map((e,t)=>X(`div`,{class:`diff-line`,"data-kind":e.kind===`-`?`removed`:e.kind===`+`?`added`:void 0,children:[X(`span`,{class:`diff-sign`,"aria-hidden":`true`,children:e.kind===` `?` `:e.kind}),X(`span`,{class:`sr-only`,children:e.kind===`-`?J(`Removed:`,`taw-core`):e.kind===`+`?J(`Added:`,`taw-core`):``}),e.text]},t)),a.length>o.length&&X(`div`,{class:`diff-line diff-more`,children:J(`… %d more lines`,`taw-core`).replace(`%d`,String(a.length-o.length))})]}):e.input.command===void 0?e.input.json===void 0?null:X(`div`,{class:`approval-body`,children:X(`pre`,{class:`approval-code`,children:e.input.json})}):X(`div`,{class:`approval-body`,children:[e.input.description&&X(`div`,{class:`approval-note`,children:e.input.description}),X(`pre`,{class:`approval-code`,children:e.input.command})]}),X(`div`,{class:`approval-actions`,children:[X(`button`,{type:`button`,class:`card-button primary`,disabled:e.state===`sending`,onClick:()=>t(e.id,!0),children:J(s?`Apply`:`Allow`,`taw-core`)}),X(`button`,{type:`button`,class:`card-button`,disabled:e.state===`sending`,onClick:()=>t(e.id,!1),children:J(`Skip`,`taw-core`)})]})]})}function At({turn:e,onRetry:t,retry:n}){let[r,i]=W(!1),a=e.changed??[],o=e.message===`connection`||e.message===`agent-exit`?J(`The connection to Claude dropped.`,`taw-core`):J(`Claude reported an error.`,`taw-core`),s=[e.message!==`connection`&&e.message!==`agent-exit`?e.message:``,e.detail??``].filter(Boolean).join(`

`);return X(`div`,{class:`notice`,role:`alert`,children:[X(`div`,{class:`notice-head`,children:[X(gt,{size:15}),X(`span`,{children:J(`Claude stopped before finishing`,`taw-core`)})]}),X(`p`,{class:`notice-text`,children:[o,` `,a.length?J(`It had changed %s by then.`,`taw-core`).replace(`%s`,a.join(`, `)):J(`Nothing was changed after the last step you saw.`,`taw-core`)]}),X(`div`,{class:`notice-actions`,children:[X(`button`,{type:`button`,class:`card-button primary`,disabled:!n,onClick:()=>t(e),children:J(`Try again`,`taw-core`)}),s&&X(`button`,{type:`button`,class:`card-button`,"aria-expanded":r,onClick:()=>i(!r),children:J(`Details`,`taw-core`)})]}),r&&s&&X(`pre`,{class:`notice-detail`,children:s})]})}var jt=e=>{if(!e)return``;let t=new Date(e);return Number.isNaN(t.getTime())?``:t.toLocaleTimeString([],{hour:`2-digit`,minute:`2-digit`})};function Mt({busy:e,takenOver:t,onTakeOver:n}){let[r,i]=W(`open`);if(t)return X(`div`,{class:`busy-line`,children:J(`You took over this theme. The other Claude session is still running: you may overwrite each other’s changes.`,`taw-core`)});let a=jt(e.since);return r===`waiting`?X(`div`,{class:`busy busy-compact`,role:`status`,children:[X(`span`,{class:`approval-dot`,"aria-hidden":`true`}),X(`span`,{children:J(`Waiting for the other Claude session. Lens writes no files until it’s free.`,`taw-core`)}),X(`button`,{type:`button`,class:`text-button`,onClick:()=>i(`confirm`),children:J(`Take over`,`taw-core`)})]}):X(`div`,{class:`busy`,role:`status`,children:[X(`div`,{class:`busy-head`,children:[X(`span`,{class:`approval-dot`,"aria-hidden":`true`}),X(`span`,{children:J(r===`confirm`?`Take over the theme?`:`Another Claude session is editing this theme`,`taw-core`)})]}),X(`p`,{class:`busy-text`,children:r===`confirm`?J(`Lens will write files again while the other session keeps running. If you both change the same file, one of you overwrites the other.`,`taw-core`):`${a?J(`A Claude session started at %s holds the theme.`,`taw-core`).replace(`%s`,a):J(`A Claude session holds the theme.`,`taw-core`)} ${J(`You can keep picking and asking; Lens won’t write files until it’s free.`,`taw-core`)}`}),X(`div`,{class:`busy-actions`,children:r===`confirm`?X(v,{children:[X(`button`,{type:`button`,class:`card-button primary`,onClick:n,children:J(`Take over`,`taw-core`)}),X(`button`,{type:`button`,class:`card-button`,onClick:()=>i(`open`),children:J(`Cancel`,`taw-core`)})]}):X(v,{children:[X(`button`,{type:`button`,class:`card-button primary`,onClick:()=>i(`waiting`),children:J(`Wait for it`,`taw-core`)}),X(`button`,{type:`button`,class:`card-button`,onClick:()=>i(`confirm`),children:J(`Take over`,`taw-core`)})]})})]})}function Nt({outdated:e}){let[t,n]=W(!1);return X(`div`,{class:`empty`,children:[X(_t,{}),X(`div`,{class:`empty-title`,children:J(e?`Lens needs a restart`:`Lens isn’t connected`,`taw-core`)}),X(`div`,{class:`empty-text`,children:J(e?`The running taw lens is from another taw/core version. Stop it (Ctrl-C) and start it again in this site’s theme folder:`:`Start it in this site’s theme folder:`,`taw-core`)}),X(`div`,{class:`command`,children:[X(`code`,{children:wt}),X(`button`,{type:`button`,class:`command-copy`,onClick:()=>{navigator.clipboard?.writeText(wt).then(()=>{n(!0),setTimeout(()=>n(!1),1500)},()=>{})},children:J(t?`Copied`:`Copy`,`taw-core`)})]}),X(`div`,{class:`empty-note`,children:J(`Lens reconnects on its own once it’s running.`,`taw-core`)}),X(`div`,{class:`empty-note`,children:J(`Picking and the inspect card still work.`,`taw-core`)})]})}var Pt=()=>X(`div`,{class:`skeleton`,"aria-hidden":`true`,children:[X(`span`,{style:{width:`58%`}}),X(`span`,{style:{width:`82%`}}),X(`span`,{style:{width:`38%`}})]});function Ft({turn:e,last:t,working:n,onAnswer:r,onRetry:i,retry:a}){let o=e.items[e.items.length-1],s=t&&n&&e.outcome===void 0&&(!o||o.type===`steps`&&o.steps.every(e=>e.state!==`running`));return X(`div`,{class:`turn`,children:[X(`div`,{class:`message`,children:[e.chips.length>0&&X(`div`,{class:`message-chips`,children:e.chips.map((e,t)=>X(`span`,{class:`chip`,children:e},t))}),X(`div`,{class:`bubble`,children:e.text})]}),e.items.map((e,t)=>e.type===`text`?X(`div`,{class:`reply`,children:X(Ct,{source:e.text})},t):e.type===`steps`?X(Dt,{steps:e.steps},t):X(kt,{item:e,onAnswer:r},e.id)),s&&X(`div`,{class:`activity`,children:X(`div`,{class:`step`,children:[X(Et,{state:`running`}),X(`span`,{class:`step-text`,children:J(`Thinking…`,`taw-core`)})]})}),e.outcome===`stopped`&&X(`div`,{class:`turn-note`,children:J(`Stopped.`,`taw-core`)}),e.outcome===`error`&&X(At,{turn:e,onRetry:i,retry:a})]})}function It({chat:e,connection:t,onAnswer:n,onRetry:r,onTakeOver:i}){let a=t===`connected`;return X(`div`,{class:`conversation`,role:`log`,"aria-label":J(`Conversation`,`taw-core`),children:[e.busy&&a&&X(Mt,{busy:e.busy,takenOver:e.takenOver,onTakeOver:i}),e.turns.map((t,i)=>X(Ft,{turn:t,last:i===e.turns.length-1,working:e.working,onAnswer:n,onRetry:r,retry:a&&!e.working&&i===e.turns.length-1},t.turn))]})}var Lt=`taw-lens-map`,Rt=e=>typeof e==`object`&&!!e&&!Array.isArray(e);function zt(e){if(!e)return null;let t;try{t=JSON.parse(e)}catch{return null}if(!Rt(t)||t.version!==1)return null;let n=Array.isArray(t.nodes)?t.nodes:Rt(t.nodes)?Object.values(t.nodes):[],r=new Map;for(let e of n)Rt(e)&&typeof e.id==`number`&&r.set(e.id,{...e,reads:Array.isArray(e.reads)?e.reads:[]});return r.has(0)?{version:1,nodes:r,files:Rt(t.files)?t.files:{}}:null}var Bt=e=>zt(e.getElementById(Lt)?.textContent);function Vt(e,t){let n=[],r=new Set;for(let i=e.nodes.get(t);i&&!r.has(i.id);)n.push(i),r.add(i.id),i=i.parent===null?void 0:e.nodes.get(i.parent);return n}var Q=(e,t)=>e.length>t?`${e.slice(0,t-1).trimEnd()}…`:e,Ht=e=>e.store===`wp`||e.store===`menu`||e.type===`wp`;function Ut(e){switch(e){case`wysiwyg`:return J(`rich text`,`taw-core`);case`textarea`:return J(`long text`,`taw-core`);case`url`:case`link`:return J(`link`,`taw-core`);case`image`:return J(`image`,`taw-core`);case`repeater`:return J(`repeater`,`taw-core`);case`number`:return J(`number`,`taw-core`);case`select`:case`radio`:return J(`choice`,`taw-core`);default:return J(`text`,`taw-core`)}}function Wt(e,t=e.key){return e.store===`menu`?`${J(`Menu item`,`taw-core`)} “${Q(String(e.value??``),32)}”`:Ht(e)?e.key===`post_title`?J(`Post title`,`taw-core`):e.label:t}var Gt=e=>e.kind===`field`?Wt(e.read,e.path):`“${Q(e.text,40)}”`,Kt=e=>e.label||e.name;function qt(e,t,n){let r=Vt(e,t).reverse().map(Kt).filter(e=>e!==``);return n&&r.push(n),r.join(` › `)}function Jt(e,t){let n=Vt(e,t).find(e=>e.kind!==`page`)??e.nodes.get(0);return n?n.name:``}function Yt(e,t){let n=t.attribution,r=Jt(e,n.kind===`block`?n.node:t.at),i=n.kind===`field`||n.kind===`template`?Gt(n.source):n.kind===`ambiguous`?J(`ambiguous`,`taw-core`):n.kind===`mixed`?J(`mixed`,`taw-core`):n.kind===`element`?t.elements[0]?.tagName.toLowerCase()??``:``;return i?`${r} › ${i}`:r}function Xt(e,t){let n=t.attribution;if(n.kind===`field`||n.kind===`template`)return Gt(n.source);if(n.kind===`block`){let t=e.nodes.get(n.node);return t?Kt(t):``}return n.kind===`element`?`<${t.elements[0]?.tagName.toLowerCase()??`?`}>`:`“${Q(n.text,24)}”`}function Zt(e,t,n=1){return e.replace(`{path}`,encodeURI(t)).replace(`{line}`,String(n))}var Qt=new Set([`checkbox`,`toggle`,`color`,`hidden`,`image`,`gallery`,`file`,`post_select`]),$t=new Set([`SCRIPT`,`STYLE`,`NOSCRIPT`,`TEMPLATE`,`HEAD`,`TITLE`]),en=new Set([`HTML`,`HEAD`,`BODY`]),tn=4e3,nn=e=>e.replace(/\s+/g,` `).trim();function rn(e,t=document){let n=t.createElement(`template`);return n.innerHTML=e,nn(n.content.textContent??``)}var an=(e,t)=>e===`wysiwyg`||/<[a-z][^>]*>/i.test(t);function on(e){let t=e;if(typeof t==`string`&&/^\s*\[/.test(t))try{t=JSON.parse(t)}catch{return null}return Array.isArray(t)?t.filter(e=>typeof e==`object`&&!!e):null}var sn=new WeakMap;function cn(e,t=document){let n=sn.get(e);if(n)return n;let r=[];for(let n of e.reads){if(Qt.has(n.type))continue;let i=n.type===`repeater`||n.subs?on(n.value):null;if(i){i.forEach((i,a)=>{for(let[o,s]of Object.entries(i)){if(typeof s!=`string`&&typeof s!=`number`)continue;let i=String(s),c=n.subs?.[o]?.type??`text`;if(!i.trim()||Qt.has(c))continue;let l=n.subs?.[o]?.label??o,u=an(c,i);r.push({source:{kind:`field`,node:e.id,read:n,path:`${n.key}[${a}].${o}`,label:`${n.label} › ${l} (row ${a+1})`},text:u?rn(i,t):nn(i),rich:u})}});continue}if(typeof n.value!=`string`&&typeof n.value!=`number`)continue;let a=String(n.value);if(!a.trim())continue;let o=an(n.type,a);r.push({source:{kind:`field`,node:e.id,read:n,path:n.key,label:n.label},text:o?rn(a,t):nn(a),rich:o})}return sn.set(e,r),r}function ln(e,t){let n=t.template;return n?(e.files[n]?.texts??[]).map(e=>({kind:`template`,node:t.id,file:n,line:e.line,text:nn(e.text),how:e.kind})):[]}function un(e){return e.kind===`field`?`f:${e.read.store}:${e.read.object}:${e.path}`:`t:${e.file}:${e.line}:${e.text}`}var dn=e=>`f:${e.store}:${e.object}:${e.key}`;function fn(e){let t=new Map;for(let n of e){let e=un(n);t.has(e)||t.set(e,n)}return[...t.values()]}function pn(e){let t=new Set(e.flatMap(e=>e.kind===`field`&&e.read.store===`menu`&&e.read.target?[e.read.target]:[]));return t.size===0?e:e.filter(e=>!(e.kind===`field`&&e.read.store===`post`&&e.read.key===`post_title`&&t.has(Number(e.read.object))))}function mn(e,t,n,r=document){let i=Vt(e,t);for(let t of i){let i=[],a=[];for(let e of cn(t,r))e.text===n?i.push(e.source):e.rich&&n.length>=2&&e.text.includes(n)&&a.push({...e.source,part:!0});for(let r of ln(e,t))r.text===n&&i.push(r);let o=fn(pn(i.length>0?i:a));if(o.length===1){let[e]=o;return e.kind===`field`?{kind:`field`,node:t.id,text:n,source:e}:{kind:`template`,node:t.id,text:n,source:e}}if(o.length>1)return{kind:`ambiguous`,node:t.id,text:n,sources:o}}for(let t of i){let i=[];for(let e of cn(t,r))e.text.length>=2&&n.includes(e.text)&&i.push(e.source);for(let r of ln(e,t))r.text.length>=2&&n.includes(r.text)&&i.push(r);if(i.length>0)return{kind:`mixed`,node:t.id,text:n,sources:fn(i)}}return{kind:`element`,node:t,text:n}}function hn(e){let t=``,n=e.ownerDocument.createTreeWalker(e,NodeFilter.SHOW_TEXT);for(let e=n.nextNode();e;e=n.nextNode())e.parentElement?.closest(`script,style,noscript,template`)||(t+=e.data);return nn(t)}var gn=class{manifest;doc;nodeByElement=new Map;roots=new Map;bySource=new Map;sourcesByKey=new Map;attributions=new Map;order=new Map;constructor(e,t,n){this.doc=e,this.manifest=t,this.scan(n??null)}nodeOf(e){return this.nodeByElement.get(e)??0}rootsOf(e){return this.roots.get(e)??[]}elementsOf(e){return this.bySource.get(e)??[]}sources(){return this.sourcesByKey}position(e){return this.order.get(e)??2**53-1}attribute(e){let t=this.attributions.get(e);if(t)return t;let n=this.compute(e);return this.attributions.set(e,n),n}isRoot(e){let t=this.nodeOf(e),n=e.parentElement;return t!==0&&(!n||en.has(n.tagName)||this.nodeOf(n)!==t)}compute(e){if(en.has(e.tagName))return{kind:`block`,node:0};let t=this.nodeOf(e);if(e.tagName===`IMG`){let n=this.imageField(e,t);if(n)return{kind:`field`,node:n.node,text:``,source:n}}let n=hn(e),r=n&&n.length<=tn?mn(this.manifest,t,n,this.doc):null;if(r&&(r.kind===`field`||r.kind===`template`||r.kind===`ambiguous`))return r;if(this.isRoot(e))return{kind:`block`,node:t};let i=e.tagName===`A`?this.linkField(e,t):null;return i?{kind:`field`,node:i.node,text:n,source:i}:r?.kind===`mixed`?r:{kind:`element`,node:t,text:n}}imageField(e,t){let n=`${e.getAttribute(`src`)??``} ${e.getAttribute(`srcset`)??``}`;for(let e of Vt(this.manifest,t)){let t=e.reads.find(e=>e.type===`image`&&e.stem&&n.includes(`/${e.stem}`));if(t)return{kind:`field`,node:e.id,read:t,path:t.key,label:t.label}}return null}linkField(e,t){let n=e.getAttribute(`href`);if(!n)return null;for(let e of Vt(this.manifest,t)){let t=e.reads.find(e=>(e.type===`url`||e.type===`link`)&&typeof e.value==`string`&&e.value===n);if(t)return{kind:`field`,node:e.id,read:t,path:t.key,label:t.label}}return null}add(e,t){let n=this.bySource.get(e);n?n.includes(t)||n.push(t):this.bySource.set(e,[t])}scan(e){let t=[0],n=new Set,r=this.doc.createTreeWalker(this.doc,NodeFilter.SHOW_COMMENT|NodeFilter.SHOW_ELEMENT|NodeFilter.SHOW_TEXT),i=0;for(let a=r.nextNode();a;a=r.nextNode()){if(a.nodeType===Node.COMMENT_NODE){let e=/^(\/)?taw-lens:(\d+)$/.exec(a.data);if(e){if(e[1]){let n=t.lastIndexOf(Number(e[2]));n>0&&(t.length=n)}else t.push(Number(e[2]))}continue}if(a.nodeType===Node.ELEMENT_NODE){let n=a;if(e&&(n===e||e.contains(n)))continue;this.nodeByElement.set(n,t[t.length-1]),this.order.set(n,i++);continue}let r=a.parentElement;r&&nn(a.data)&&!$t.has(r.tagName)&&!r.closest(`script,style,noscript,template,head`)&&!(e&&e.contains(r))&&n.add(r)}for(let[e,t]of this.nodeByElement)if(t!==0&&this.isRoot(e)&&!$t.has(e.tagName)&&e.tagName!==`LINK`&&e.tagName!==`META`){let n=this.roots.get(t)??[];n.push(e),this.roots.set(t,n)}for(let[e,t]of this.nodeByElement)if(t===0&&!en.has(e.tagName)&&!$t.has(e.tagName)&&e.parentElement){let t=e.parentElement;if(en.has(t.tagName)||this.nodeOf(t)!==0){let t=this.roots.get(0)??[];t.push(e),this.roots.set(0,t)}}let a=[...new Set([...n,...this.doc.querySelectorAll(`img, a[href]`)])].filter(t=>!(e&&e.contains(t)));for(let e of a){let t=this.attribute(e),n=t.kind===`field`||t.kind===`template`?[t.source]:t.kind===`ambiguous`?t.sources:[];for(let t of n){let n=un(t);this.add(n,e),this.sourcesByKey.has(n)||this.sourcesByKey.set(n,t),t.kind===`field`&&this.add(dn(t.read),e)}}}};function _n({title:e,mono:t,badge:n,onClose:r}){return X(`div`,{class:`card-head`,children:[X(`span`,{class:t?`card-title mono`:`card-title`,children:e}),X(`span`,{class:`card-badge`,children:n}),r&&X(`button`,{type:`button`,class:`card-close`,"aria-label":J(`Clear selection`,`taw-core`),onClick:r,children:X(at,{})})]})}var vn=({children:e})=>X(`div`,{class:`card-actions`,children:e}),yn=()=>X(`button`,{type:`button`,class:`card-button primary`,"aria-disabled":`true`,title:J(`Editing in place is coming in a later build`,`taw-core`),children:J(`Edit here`,`taw-core`)}),bn=({href:e,children:t,primary:n})=>X(`a`,{class:n?`card-button primary`:`card-button`,href:e,target:`_blank`,rel:`noopener noreferrer`,children:t});function xn(e){let t=e.read.value,n=/\[(\d+)\]\.(.+)$/.exec(e.path);if(n)try{let e=typeof t==`string`?JSON.parse(t):t;t=Array.isArray(e)?e[Number(n[1])]?.[n[2]]:t}catch{}else if(e.read.type===`repeater`)try{let e=typeof t==`string`?JSON.parse(t):t;if(Array.isArray(e))return e.length===1?J(`1 row`,`taw-core`):J(`%d rows`,`taw-core`).replace(`%d`,String(e.length))}catch{}if(e.read.type===`image`)return e.read.stem??String(t??``);let r=typeof t==`string`||typeof t==`number`?String(t):JSON.stringify(t??``);return Q(/<[a-z][^>]*>/i.test(r)?rn(r):r,600)}function Sn({manifest:e,sources:t,editor:n}){return X(`ul`,{class:`card-sources`,children:t.map(t=>X(`li`,{children:[X(`span`,{class:`card-source-name mono`,children:Gt(t)}),X(`span`,{class:`card-source-where`,children:t.kind===`field`?qt(e,t.node,t.label):`${t.file}:${t.line}`}),t.kind===`field`?t.read.admin&&X(`a`,{class:`card-link`,href:t.read.admin,target:`_blank`,rel:`noopener noreferrer`,children:J(`Open in wp-admin`,`taw-core`)}):X(Cn,{manifest:e,source:t,editor:n})]},t.kind===`field`?t.path+t.read.object:`${t.file}:${t.line}`))})}function Cn({manifest:e,source:t,editor:n}){let r=e.files[t.file]?.abs;return r?X(`a`,{class:`card-link`,href:Zt(n,r,t.line),children:J(`Open in editor`,`taw-core`)}):null}function wn({manifest:e,node:t}){let n=e.nodes.get(t)?.template;return n?X(`div`,{class:`card-meta mono`,children:[J(`Rendered by`,`taw-core`),` `,n]}):null}function Tn({manifest:e,target:t,editor:n,onClose:r,onShowInTree:i}){let a=t.attribution,o=e.nodes.get(t.at);if(a.kind===`field`){let{read:n}=a.source,i=Ht(n),o=Wt(n,a.source.path),s=i?J(`WordPress`,`taw-core`):`${J(`Field`,`taw-core`)} · ${Ut(n.subs?.[a.source.path.split(`.`).pop()??``]?.type??n.type)}`;return X(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[X(_n,{title:o,mono:!i,badge:s,onClose:r}),X(`div`,{class:`card-location`,children:qt(e,a.source.node,a.source.label)}),X(`div`,{class:`card-value`,children:xn(a.source)||X(`span`,{class:`card-empty`,children:J(`(empty)`,`taw-core`)})}),n.default&&X(`div`,{class:`card-note`,children:J(`The field’s default: nothing is saved yet.`,`taw-core`)}),a.source.part&&X(`div`,{class:`card-note`,children:J(`Part of this rich-text field.`,`taw-core`)}),X(wn,{manifest:e,node:t.at}),X(vn,{children:[i?null:X(yn,{}),n.admin&&X(bn,{href:n.admin,children:J(`Open in wp-admin`,`taw-core`)})]})]})}if(a.kind===`template`){let{source:t}=a,i=e.files[t.file],o=i?.lines??{},s=[t.line-1,t.line,t.line+1].filter(e=>o[String(e)]!==void 0);return X(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[X(_n,{title:`“${Q(t.text,60)}”`,badge:t.how===`i18n`?J(`Template text · translatable`,`taw-core`):J(`Template text`,`taw-core`),onClose:r}),X(`div`,{class:`card-location`,children:qt(e,t.node)}),s.length>0&&X(`div`,{class:`card-code mono`,role:`group`,"aria-label":`${t.file}:${t.line}`,children:s.map(e=>X(`div`,{class:`card-code-line`,"data-current":e===t.line?``:void 0,children:[X(`span`,{class:`card-code-number`,children:e}),X(`span`,{class:`card-code-text`,children:o[String(e)]})]},e))}),X(`div`,{class:`card-meta mono`,children:[t.file,`:`,t.line]}),X(vn,{children:[X(yn,{}),i?.abs&&X(bn,{href:Zt(n,i.abs,t.line),children:J(`Open in editor`,`taw-core`)})]})]})}if(a.kind===`block`){let o=e.nodes.get(a.node);if(!o)return null;let s=o.kind===`block`?`${J(`Block`,`taw-core`)} · ${o.name}`:o.kind===`part`?J(`Template part`,`taw-core`):J(`Page`,`taw-core`),c=o.files??(o.template?[o.template]:[]),l=[...new Set(o.reads.filter(e=>!Ht(e)&&e.store!==`option`).map(e=>e.key))],u=o.template?e.files[o.template]:void 0;return X(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[X(_n,{title:Kt(o),badge:s,onClose:r}),X(`div`,{class:`card-location`,children:qt(e,o.id)}),X(`ul`,{class:`card-files mono`,children:c.map(t=>{let r=e.files[t]?.abs;return X(`li`,{children:r?X(`a`,{href:Zt(n,r),children:t}):t},t)})}),l.length>0&&X(`div`,{class:`card-meta`,children:[J(`Fields:`,`taw-core`),` `,l.join(`, `)]}),X(vn,{children:[u?.abs&&X(bn,{href:Zt(n,u.abs),children:J(`Open in editor`,`taw-core`)}),X(`button`,{type:`button`,class:`card-button`,onClick:()=>i(t),children:J(`Show in tree`,`taw-core`)})]})]})}if(a.kind===`ambiguous`)return X(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[X(_n,{title:`“${Q(a.text,60)}”`,badge:J(`Ambiguous`,`taw-core`),onClose:r}),X(`div`,{class:`card-location`,children:qt(e,a.node)}),X(`div`,{class:`card-note`,children:J(`More than one source holds this exact text, so Lens can’t tell which one rendered it. It doesn’t guess:`,`taw-core`)}),X(Sn,{manifest:e,sources:a.sources,editor:n})]});if(a.kind===`mixed`)return X(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[X(_n,{title:`“${Q(a.text,60)}”`,badge:J(`Mixed`,`taw-core`),onClose:r}),X(`div`,{class:`card-location`,children:qt(e,t.at)}),X(`div`,{class:`card-note`,children:J(`Template text around values. Made of:`,`taw-core`)}),X(Sn,{manifest:e,sources:a.sources,editor:n}),X(wn,{manifest:e,node:t.at})]});let s=t.elements[0]?.tagName.toLowerCase()??``,c=o?.template?e.files[o.template]:void 0;return X(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[X(_n,{title:`<${s}>`,mono:!0,badge:J(`Element`,`taw-core`),onClose:r}),X(`div`,{class:`card-location`,children:qt(e,t.at)}),X(`div`,{class:`card-note`,children:a.text?J(`Its text isn’t a TAW field or template text: it may be computed in the template, or added by JavaScript.`,`taw-core`):J(`No text of its own. Its template rendered it.`,`taw-core`)}),X(wn,{manifest:e,node:t.at}),X(vn,{children:c?.abs&&X(bn,{href:Zt(n,c.abs),children:J(`Open in editor`,`taw-core`)})})]})}function En(e,t,n=400){return e<120?`left`:e+n>t.width-120?`right`:null}var Dn=e=>Math.max(240,Math.min(600,e.height-e.top-24));function On(e){return kn({x:e.width-400-24,y:e.top+72},e)}function kn(e,t,n=400){let r=Math.max(12,t.width-n-12),i=t.top+12,a=Math.max(i,t.height-Dn(t)-12);return{x:Math.round(Math.min(r,Math.max(12,e.x))),y:Math.round(Math.min(a,Math.max(i,e.y)))}}var An=(e,t,n=400)=>e.x+n/2<t.width/2?`left`:`right`;function jn(e,t=400){return`html { margin-${e}: ${t}px !important; }`}function Mn(e,t,n=400){return{left:t===`left`?n:0,top:e.top,right:t===`right`?e.width-n:e.width,bottom:e.height}}function Nn(e,t,n){let r=Math.max(n.left+1,e.left-t),i=Math.max(n.top+1,e.top-t),a=Math.min(n.right-1,e.left+e.width+t),o=Math.min(n.bottom-1,e.top+e.height+t);return{left:r,top:i,width:Math.max(0,a-r),height:Math.max(0,o-i)}}function Pn([e,t,n]){let r=e=>{let t=e/255;return t<=.04045?t/12.92:((t+.055)/1.055)**2.4};return .2126*r(e)+.7152*r(t)+.0722*r(n)}var Fn=`#1A1C21`,In=`#FFFFFF`,Ln=[26,28,33],Rn=[255,255,255];function zn(e){let t=t=>{let[n,r]=e>t?[e,t]:[t,e];return(n+.05)/(r+.05)};return t(Pn(Ln))>=t(Pn(Rn))?{ring:Fn,halo:In,luminance:e}:{ring:In,halo:Fn,luminance:e}}var Bn=(e,t)=>[e.r*e.a+t[0]*(1-e.a),e.g*e.a+t[1]*(1-e.a),e.b*e.a+t[2]*(1-e.a)];function Vn(e){let t=Rn;for(let n=e.length-1;n>=0;n--)t=Bn(e[n],t);return t}function Hn(e,t){let n=e.trim().toLowerCase();if(n===`transparent`||n===``)return{r:0,g:0,b:0,a:0};let r=/^#([0-9a-f]{3,8})$/.exec(n);if(r){let e=r[1],t=e.length<=4?[...e].map(e=>e+e).join(``):e;if(t.length===6||t.length===8){let e=e=>parseInt(t.slice(e,e+2),16);return{r:e(0),g:e(2),b:e(4),a:t.length===8?e(6)/255:1}}}let i=/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/.exec(n);if(i){let e=i[4]===void 0?1:i[4].endsWith(`%`)?parseFloat(i[4])/100:parseFloat(i[4]);return{r:+i[1],g:+i[2],b:+i[3],a:Math.max(0,Math.min(1,e))}}return t?t(e):null}function Un(e){return e.match(/(?:rgba?|hsla?|oklch|oklab|lab|lch|color)\([^()]*\)|#[0-9a-f]{3,8}\b/gi)??[]}function Wn(e){if(e.length===0)return null;let t=e.reduce((e,t)=>({r:e.r+t.r,g:e.g+t.g,b:e.b+t.b,a:e.a+t.a}),{r:0,g:0,b:0,a:0}),n=e.length;return{r:t.r/n,g:t.g/n,b:t.b/n,a:t.a/n}}function Gn(e,t,n){let r=e.left-t,i=e.left+e.width+t,a=e.top-t,o=e.top+e.height+t,s=e.left+e.width/2,c=e.top+e.height/2;return[[r,a],[s,a],[i,a],[i,c],[i,o],[s,o],[r,o],[r,c]].map(([e,t])=>[Math.round(e),Math.round(t)]).filter(([e,t])=>e>=0&&t>=0&&e<n.width&&t<n.height)}var Kn=new Set([`IMG`,`VIDEO`,`CANVAS`]);function qn(e,t,n){let r=[];for(let i of e.stackAt(t,n)){let a=e.style(i);if(a.visibility===`hidden`||parseFloat(a.opacity)===0)continue;if(Kn.has(i.tagName)){let a=e.media(i,t,n);if(a&&(r.push(a),a.a>=.999))break;continue}let o=a.backgroundImage;if(o&&o!==`none`){let a=/url\((['"]?)(.*?)\1\)/.exec(o)?.[2],s=(a?e.backgroundImage(i,a,t,n):null)??Wn(Un(o).flatMap(t=>e.color(t)??[]));if(s&&s.a>0&&(r.push(s),s.a>=.999))break}let s=e.color(a.backgroundColor);if(s&&s.a>0&&(r.push(s),s.a>=.999))break}return Vn(r)}function Jn(e,t,n,r){let i=Gn(t,n,r),a=(i.length>0?i:[[Math.round(t.left+t.width/2),Math.round(t.top+t.height/2)]]).map(([t,n])=>Pn(qn(e,t,n)));return zn(a.reduce((e,t)=>e+t,0)/a.length)}var Yn={hover:4,selected:7},Xn=24;function Zn({rings:e,sampler:t,top:n,area:r,refresh:i=0}){let[a,o]=G(e=>e+1,0),s=q(new Map),c=e.length>0,l=q(i);K(()=>{if(!c)return;let e=0,t=0,n=()=>{e||=requestAnimationFrame(()=>{e=0,o(void 0)}),clearTimeout(t),t=window.setTimeout(()=>{s.current.clear(),o(void 0)},150)};window.addEventListener(`scroll`,n,{capture:!0,passive:!0}),window.addEventListener(`resize`,n,{passive:!0});let r=window.setInterval(()=>o(void 0),600);return()=>{cancelAnimationFrame(e),clearTimeout(t),clearInterval(r),window.removeEventListener(`scroll`,n,{capture:!0}),window.removeEventListener(`resize`,n)}},[c]);let u={width:document.documentElement.clientWidth||window.innerWidth,height:window.innerHeight},d=_e(()=>(l.current!==i&&(l.current=i,s.current.clear()),e.flatMap(e=>{if(!e.el.isConnected)return[];let n=e.el.getBoundingClientRect();if(n.width<=0&&n.height<=0)return[];let r=Yn[e.state];if(n.bottom+r<0||n.top-r>u.height)return[];let i=s.current.get(e.el);return i||(i=t?Jn(t,n,r,u):{ring:`#1A1C21`,halo:`#FFFFFF`,luminance:1},s.current.set(e.el,i)),[{spec:e,rect:n,outset:r,color:i}]})),[e,t,a,i,u.width,u.height]);return d.length===0?null:X(`div`,{class:`overlay`,"aria-hidden":`true`,children:d.map(({spec:e,rect:t,outset:i,color:a})=>{let o=r??{left:0,top:n,right:u.width,bottom:u.height},s=Nn(t,i,o);if(s.width<=0||s.height<=0)return null;let c=s.top-6-Xn,l=c>=o.top+2?c:Math.min(s.top+s.height+6,o.bottom-Xn-2),d=Math.max(o.left+4,Math.min(s.left,o.right-120));return X(`div`,{children:[X(`div`,{class:`ring`,"data-state":e.state,style:{left:`${s.left}px`,top:`${s.top}px`,width:`${s.width}px`,height:`${s.height}px`,"--ring":a.ring,"--halo":a.halo}}),e.label&&X(`div`,{class:`ring-label`,style:{left:`${d}px`,top:`${l}px`,maxWidth:`${Math.max(120,o.right-d-8)}px`,"--ring":a.ring,"--halo":a.halo},children:e.label})]},e.key)})})}var Qn=`taw-lens:prefs`,$n={mode:`docked`,shown:`docked`,side:`right`,sheet:null,theme:`system`},er=(e,t,n)=>typeof e==`string`&&t.includes(e)?e:n,tr=e=>typeof e==`number`&&Number.isFinite(e);function nr(e){if(typeof e!=`object`||!e)return{...$n};let t=e,n=t.sheet;return{mode:er(t.mode,[`docked`,`sheet`,`hidden`],$n.mode),shown:er(t.shown,[`docked`,`sheet`],$n.shown),side:er(t.side,[`left`,`right`],$n.side),sheet:typeof n==`object`&&n&&tr(n.x)&&tr(n.y)?{x:n.x,y:n.y}:null,theme:er(t.theme,[`system`,`light`,`dark`],$n.theme)}}function rr(e=ar()){try{let t=e?.getItem(Qn);return nr(t?JSON.parse(t):null)}catch{return{...$n}}}function ir(e,t=ar()){try{t?.setItem(Qn,JSON.stringify(e))}catch{}}function ar(){try{return window.localStorage}catch{return null}}var or=e=>e===`system`?`light`:e===`light`?`dark`:`system`,sr=400,cr=(e,t=sr)=>JSON.stringify(Q(e.replace(/\s+/g,` `).trim(),t));function lr(e){let t=[];for(let n=e;n&&t.length<5;n=n.parentElement){let e=n.tagName.toLowerCase();if(e===`html`||e===`body`)break;if(n.id&&/^[A-Za-z][\w-]*$/.test(n.id)){t.unshift(`#${n.id}`);break}let r=e+[...n.classList].filter(e=>/^[A-Za-z_-][\w-]*$/.test(e)).slice(0,2).map(e=>`.${e}`).join(``),i=n.parentElement;if(i){let e=[...i.children].filter(e=>e.tagName===n.tagName);e.length>1&&(r+=`:nth-of-type(${e.indexOf(n)+1})`)}t.unshift(r)}return t.join(` > `)}function ur(e){switch(e.store){case`post`:return`post ${e.object}`;case`term`:return`term ${e.object}`;case`user`:return`user ${e.object}`;case`option`:return`options page ${e.object} (taw fields:set options …)`;case`menu`:return`menu ${e.object}, ${e.key}`;default:return`WordPress settings`}}function dr(e,t){let n=Vt(e,t),r=n[0],i=n.slice().reverse().map(Kt).filter(Boolean).join(` › `);return r?`${i} (${r.kind===`block`?`block ${r.name}`:r.kind===`part`?`template part`:`page template`}${r.template?`, ${r.template}`:``})`:i}function fr(e,t){let{read:n}=t,r=[];if(Ht(n))r.push(`WordPress data: ${n.label}${n.key===`post_title`?` of post ${n.object}`:``}`),n.store===`menu`&&r.push(`Menu item ${n.key} in menu ${n.object}${n.target?`, linking to post ${n.target}`:``}`);else{let e=/^(.+?)\[(\d+)\]\.(.+)$/.exec(t.path);r.push(e?`Field ${e[1]}, row ${Number(e[2])+1}, sub-field ${e[3]} (${t.label}; repeater)`:`Field ${t.path} (${t.label}, ${n.type})`),r.push(`Stored on: ${ur(n)}`)}let i=typeof n.value==`string`?n.value:JSON.stringify(n.value??``);return r.push(`Value: ${cr(i)}${t.part?` (the element shows part of it)`:``}`),n.default&&r.push(`Nothing is stored yet: the page shows the field’s default.`),r.push(`Rendered in: ${dr(e,t.node)}`),n.admin&&r.push(`By hand: ${n.admin}`),r}function pr(e,t){let n=t.how===`i18n`?`, translatable (inside a gettext call such as esc_html_e)`:t.how===`literal`?`, a PHP string`:``;return[`Template text ${cr(t.text,200)}`,`File: ${t.file} line ${t.line}${n}`,`Rendered in: ${dr(e,t.node)}`]}var mr=(e,t)=>t.kind===`field`?fr(e,t):pr(e,t);function hr(e,t){let n=t.attribution;switch(n.kind){case`field`:case`template`:return mr(e,n.source);case`ambiguous`:return[`Ambiguous: the text ${cr(n.text,200)} comes from ${n.sources.length} places. Don't pick one: ask which.`,...n.sources.flatMap((t,n)=>[`Candidate ${n+1}:`,...mr(e,t).map(e=>`  ${e}`)])];case`mixed`:return[`Mixed: template text around values. The text: ${cr(n.text,200)}`,...n.sources.flatMap((t,n)=>[`Piece ${n+1}:`,...mr(e,t).map(e=>`  ${e}`)])];case`block`:{let t=e.nodes.get(n.node);if(!t)return[`A block`];let r=[t.kind===`block`?`Block ${t.name} (${Kt(t)})`:t.kind===`part`?`Template part ${t.name}`:`The page (${t.template??t.name})`,`Rendered in: ${dr(e,n.node)}`];t.files?.length?r.push(`Files: ${t.files.join(`, `)}`):t.template&&r.push(`File: ${t.template}`);let i=t.reads.filter(e=>!Ht(e)).map(e=>`${e.key} (${e.label})`);return i.length&&r.push(`Its fields: ${i.join(`, `)}`),r}default:return[`An element whose text TAW didn't render from a field or template text${n.text?`: ${cr(n.text,200)}`:``}. JavaScript may add it, or it is computed.`,`Rendered in: ${dr(e,n.node)}`]}}function gr(e,t,n){if(!e||t.length===0)return``;let r=t.map((t,n)=>{let r=t.elements[0],i=hr(e,t);return r&&i.push(`Element: <${r.tagName.toLowerCase()}> at \`${lr(r)}\``),[`${n+1}. ${i[0]}`,...i.slice(1).map(e=>`   ${e}`)].join(`
`)});return[`<lens-selections page=${JSON.stringify(n.title)} url=${JSON.stringify(n.url)}>`,`What the developer selected on the page, from TAW’s source map:`,...r,`</lens-selections>`].join(`
`)}function _r(e,t){return e?t.map(t=>{let n=t.attribution,r=n.kind===`block`?e.nodes.get(n.node):void 0,i=n.kind===`field`?Ht(n.source.read)?J(`WordPress`,`taw-core`):J(`field`,`taw-core`):n.kind===`template`?J(`template text`,`taw-core`):n.kind===`block`?r?.kind===`page`?J(`page`,`taw-core`):r?.kind===`part`?J(`template part`,`taw-core`):J(`block`,`taw-core`):n.kind===`ambiguous`?J(`ambiguous`,`taw-core`):n.kind===`mixed`?J(`mixed`,`taw-core`):J(`element`,`taw-core`);return`${n.kind===`element`?Yt(e,t):Xt(e,t)} · ${i}`}):[]}var vr=e=>e?e.split(`/`).pop()??e:``;function yr(e,t){let n=t.id===0?[]:e.rootsOf(t.id),r=t.kind;return{id:`n:${t.id}`,kind:r,name:Kt(t),detail:r===`block`?t.name:r===`page`?vr(t.template):t.template??t.name,children:[],target:{key:`n:${t.id}`,attribution:{kind:`block`,node:t.id},elements:n,at:t.id},position:Math.min(...n.map(t=>e.position(t)),2**53-1)}}function br(e,t,n,r){let i=Math.min(...r.map(t=>e.position(t)),2**53-1),a=r[0]?e.nodeOf(r[0]):n.node;if(n.kind===`template`)return{id:t,kind:`template`,name:Q(n.text,40),detail:`${n.file}:${n.line}`,children:[],target:{key:t,attribution:{kind:`template`,node:n.node,text:n.text,source:n},elements:r,at:a},position:i};let o={kind:`field`,node:n.node,read:n.read,path:n.read.key,label:n.read.label},s=Ht(n.read);return{id:t,kind:s?`wp`:`field`,name:Wt(n.read),detail:J(s?`WordPress`:`field`,`taw-core`),children:[],target:{key:t,attribution:{kind:`field`,node:n.node,text:``,source:o},elements:r,at:a},position:i}}function xr(e){let t=new Map;for(let n of e.manifest.nodes.values())t.set(n.id,yr(e,n));let n=new Map;for(let t of e.sources().values()){let e=t.kind===`field`?dn(t.read):un(t);n.has(e)||n.set(e,{node:t.node,source:t})}for(let[r,{node:i,source:a}]of n){let n=t.get(i),o=e.elementsOf(r);n&&o.length>0&&n.children.push(br(e,r,a,o))}for(let n of e.manifest.nodes.values())n.parent!==null&&t.get(n.parent)?.children.push(t.get(n.id));let r=e=>{for(let t of e.children)e.position=Math.min(e.position,r(t));let t=e=>e.kind===`page`||e.kind===`part`||e.kind===`block`;return e.children.sort((e,n)=>e.position-n.position||Number(t(n))-Number(t(e))),e.position},i=t.get(0);return r(i),i}var Sr=(e,t)=>e.name.toLowerCase().includes(t)||e.detail.toLowerCase().includes(t);function Cr(e,t,n=``){let r=n.trim().toLowerCase(),i=[],a=new Set;if(r){let t=e=>{let n=Sr(e,r);for(let r of e.children)n=t(r)||n;return n&&a.add(e.id),n};t(e)}let o=(e,n,s)=>{if(r&&!a.has(e.id))return;let c=r?!0:t.has(e.id);if(i.push({node:e,depth:n,parent:s,expanded:c&&e.children.length>0}),c)for(let t of e.children)o(t,n+1,e.id)};return o(e,0,null),i}function wr(e,t){let n=[],r=e=>{if(e.id===t)return!0;for(let t of e.children)if(r(t))return n.unshift(e.id),!0;return!1};return r(e)?n:[]}function Tr(e){let t=e.attribution;return t.kind===`block`?`n:${t.node}`:t.kind===`field`?dn(t.source.read):t.kind===`template`?un(t.source):null}var Er=({kind:e})=>X(e===`page`?ct:e===`part`?lt:e===`block`?ut:e===`template`?ft:dt,{});function Dr({root:e,selected:t,reveal:n,onHover:r,onSelect:i,onBack:a}){let[o,s]=W(``),[c,l]=W(()=>new Set([e.id])),[u,d]=W(e.id),f=q(u),p=e=>{f.current=e,d(e)},[m,h]=W(null),g=q(new Map),_=q(!1);K(()=>{let r=[...t,...n?[n]:[]].flatMap(t=>wr(e,t));n&&r.push(n),r.length>0&&l(e=>new Set([...e,...r])),n&&(p(n),_.current=!0)},[e,n,t]);let v=_e(()=>Cr(e,c,o),[e,c,o]),y=v[Math.max(0,v.findIndex(e=>e.node.id===u))];ge(()=>{_.current&&y&&(g.current.get(y.node.id)?.focus(),g.current.get(y.node.id)?.scrollIntoView?.({block:`nearest`}),_.current=!1)},[y]);let b=(e,t)=>l(n=>{let r=new Set(n);return t??!r.has(e)?r.add(e):r.delete(e),r}),x=e=>{let t=v[Math.max(0,Math.min(v.length-1,e))];t&&(_.current=!0,p(t.node.id),r(t.node.target))};return X(`div`,{class:`structure`,children:[X(`div`,{class:`structure-head`,children:[X(`span`,{class:`structure-title`,id:`lens-structure-title`,children:J(`Structure`,`taw-core`)}),X(`button`,{type:`button`,class:`text-button`,onClick:a,children:J(`Back to chat`,`taw-core`)})]}),X(`label`,{class:`sr-only`,for:`lens-tree-filter`,children:J(`Filter`,`taw-core`)}),X(`input`,{id:`lens-tree-filter`,class:`filter`,type:`search`,autocomplete:`off`,spellcheck:!1,placeholder:J(`Filter blocks, fields, files…`,`taw-core`),value:o,onInput:e=>s(e.currentTarget.value)}),X(`div`,{class:`tree`,role:`tree`,"aria-labelledby":`lens-structure-title`,onKeyDown:e=>{let t=v.findIndex(e=>e.node.id===f.current),n=Math.max(0,t),r=v[n];if(!r)return;let{node:a,expanded:o,parent:s}=r;switch(e.key){case`ArrowDown`:x(n+1);break;case`ArrowUp`:x(n-1);break;case`Home`:x(0);break;case`End`:x(v.length-1);break;case`ArrowRight`:a.children.length>0&&!o?b(a.id,!0):o&&x(n+1);break;case`ArrowLeft`:o?b(a.id,!1):s&&x(v.findIndex(e=>e.node.id===s));break;case`Enter`:case` `:i(a.target,e.shiftKey);break;default:return}e.preventDefault()},onMouseLeave:()=>{h(null),r(null)},children:[v.map(({node:e,depth:n,expanded:a})=>{let o=t.has(e.id),s=e.target.elements.length>0;return X(`div`,{ref:t=>{t?g.current.set(e.id,t):g.current.delete(e.id)},class:`tree-row`,role:`treeitem`,"aria-level":n+1,"aria-selected":o,"aria-expanded":e.children.length>0?a:void 0,tabIndex:e.id===y?.node.id?0:-1,"data-selected":o?``:void 0,style:{paddingLeft:`${8+n*16}px`},onMouseEnter:()=>{h(e.id),r(e.target)},onFocus:()=>p(e.id),onClick:t=>{p(e.id),i(e.target,t.shiftKey)},children:[e.children.length>0?X(`span`,{class:`tree-twisty`,onClick:t=>{t.stopPropagation(),b(e.id)},children:X(a?ot:st,{})}):X(`span`,{class:`tree-twisty`}),X(`span`,{class:`tree-icon`,children:X(Er,{kind:e.kind})}),X(`span`,{class:`tree-name`,children:e.name}),X(`span`,{class:`tree-detail mono`,children:e.detail}),m===e.id&&s&&X(`span`,{class:`tree-hint`,children:J(`on page`,`taw-core`)})]},e.id)}),v.length===0&&X(`div`,{class:`tree-none`,children:J(`Nothing matches.`,`taw-core`)})]}),X(`div`,{class:`structure-help`,children:J(`Hover a row to ring it on the page. Click to select; shift-click to add it to the chat.`,`taw-core`)})]})}var Or=new WeakMap,kr=0,Ar=e=>{let t=Or.get(e);return t||(t=`e:${++kr}`,Or.set(e,t)),t},jr=e=>J(e===`light`?`Theme: light. Switch to dark`:e===`dark`?`Theme: dark. Follow the system`:`Theme: follows the system. Switch to light`,`taw-core`),Mr=({theme:e})=>X(e===`light`?nt:e===`dark`?rt:tt,{});function Nr({host:e,initial:t,env:n}){let[r,i]=W(t),[a,o]=W(()=>n.viewport()),[s,c]=W(null),[l,u]=W(null),[d,f]=W(``),p=q(null),m=q(null),h=q(null),g=ve(e=>i(t=>({...t,...e})),[]),_=n.page,[v,y]=W(void 0),b=v??null,[x,S]=W(!1),[C,w]=W(`chat`),[T,E]=W([]),[D,O]=W(null),[k,A]=W(null),[j,ee]=W(null),[M,te]=W(0),[N,ne]=W(!0),[P,re]=W(n.bridge?`connecting`:`off`),[F,I]=W(Fe),[L,R]=W(``),z=q(null),B=q(P),V=q(null),ie=q(null),ae=q(!0),oe=q(null);K(()=>{if(!n.bridge)return;let e=e=>{e.type===`approval`?f(J(`Claude needs your OK for a change`,`taw-core`)):e.type===`done`&&f(e.outcome===`ok`?J(`Claude finished`,`taw-core`):e.outcome===`stopped`?J(`Stopped`,`taw-core`):J(`Claude stopped before finishing`,`taw-core`))},t=n.bridge({status(e){B.current===`connected`&&e!==`connected`&&I(Be),B.current=e,re(e)},event(t){I(e=>ze(e,t)),e(t)}});return z.current=t,()=>{t.close(),z.current=null}},[n]),ge(()=>{let e=ie.current;e&&ae.current&&(e.scrollTop=e.scrollHeight)},[F]);let se=()=>{let e=ie.current;e&&(ae.current=e.scrollHeight-e.scrollTop-e.clientHeight<48)},ce=ve(()=>{let e=_?.map(!0)??null;return y(e),e},[_]);K(()=>_?.onAsset(()=>te(e=>e+1)),[_]);let le=ve((e,t)=>({key:Ar(t),attribution:e?e.attribute(t):{kind:`element`,node:0,text:``},elements:[t],at:e?e.nodeOf(t):0}),[]),H=ve((e,t)=>{E(n=>t?n.some(t=>t.key===e.key)?n.filter(t=>t.key!==e.key):[...n,e]:[e]),O(e.key),ne(!0),f(`${J(`Selected`,`taw-core`)} ${b?Xt(b.manifest,e):``}`.trim())},[b]),ue=()=>{E([]),O(null),f(J(`Selection cleared`,`taw-core`))};K(()=>{if(!x||!_||r.mode===`hidden`)return;if(v===void 0){ce();return}let e=b,t=_.pick({hover:t=>A(t?{target:le(e,t),label:!0}:null),pick:(t,n)=>{let r=le(e,t);H(r,n);let i=Tr(r);i&&ee(i)},stop:()=>S(!1)});return()=>{t(),A(null)}},[x,_,r.mode,v,b,ce,le,H]);let de=_e(()=>b?xr(b):null,[b]),fe=_e(()=>new Set(T.map(Tr).filter(e=>e!==null)),[T]),pe=()=>{if(C===`tree`){w(`chat`);return}ce(),w(`tree`)},me=e=>{b||ce(),w(`tree`),ee(Tr(e))},he=(e,t)=>{H(e,t),e.elements[0]&&_?.reveal(e.elements[0])},U=T.find(e=>e.key===D)??T[T.length-1]??null,G=P===`connected`,ye=async e=>{let t=z.current;if(!t||!G||F.working||e.text.trim()===``)return;ae.current=!0;let n=await t.send(`/message`,e);return n.ok?(V.current=e,!0):(f(n.status===409?J(`Claude is still working on the last message`,`taw-core`):J(`Lens couldn’t send that. Is taw lens still running?`,`taw-core`)),!1)},be=async()=>{let e=L.trim();if(e===``)return;let t={text:e,context:gr(b?.manifest??null,T,{url:_?.url??``,title:b?.manifest.nodes.get(0)?.label??``}),chips:_r(b?.manifest??null,T)};R(``),await ye(t)?ne(!1):R(e)},xe=e=>{let t=V.current;ye(t&&t.text===e.text?t:{text:e.text,context:``,chips:e.chips})},Se=async(e,t)=>{I(t=>Ve(t,e,`sending`)),(await z.current?.send(`/approve`,{id:e,allow:t}))?.ok||(I(t=>Ve(t,e,`pending`)),f(J(`Lens couldn’t send your answer. Try again.`,`taw-core`)))},Ce=()=>void z.current?.send(`/stop`,{}),we=()=>void z.current?.send(`/take-over`,{}),Te=e=>{e.key===`Enter`&&!e.shiftKey&&!e.isComposing&&(e.preventDefault(),be())};ge(()=>{let e=oe.current;e&&(e.style.height=`auto`,e.style.height=`${Math.min(e.scrollHeight,132)}px`)},[L]);let Ee=r.mode===`hidden`?[]:[...T.flatMap(e=>e.elements.map((t,n)=>({key:`${e.key}:${n}`,el:t,state:`selected`,label:n===0&&b?Yt(b.manifest,e):void 0}))),...k?k.target.elements.filter(e=>!T.some(t=>t.elements.includes(e))).map((e,t)=>({key:`hover:${t}`,el:e,state:`hover`,label:k.label&&t===0&&b?Yt(b.manifest,k.target):void 0})):[]];K(()=>n.push(r.mode===`docked`?r.side:null),[n,r.mode,r.side]),K(()=>n.theme(r.theme),[n,r.theme]),K(()=>n.save(r),[n,r]),K(()=>n.onResize(()=>o(n.viewport())),[n]),K(()=>{h.current===`reopen`?p.current?.focus():h.current===`hide`&&m.current?.focus(),h.current=null},[r.mode]);let De=kn(r.sheet??On(a),a),Oe=e=>{g({mode:`docked`,shown:`docked`,side:e}),f(J(e===`left`?`Lens docked on the left`:`Lens docked on the right`,`taw-core`))},ke=()=>{g({mode:`sheet`,shown:`sheet`,sheet:De}),f(J(`Lens undocked into a sheet`,`taw-core`))},Ae=()=>{h.current=`reopen`,S(!1),g({mode:`hidden`})},je=()=>{h.current=`hide`,g({mode:r.shown})},Me=e=>{r.mode!==`sheet`||e.button!==0||e.target.closest(`button`)||(e.currentTarget.setPointerCapture?.(e.pointerId),c({pointer:e.pointerId,start:{x:e.clientX,y:e.clientY},from:De}),e.preventDefault())},Ne=e=>{if(!s||e.pointerId!==s.pointer)return;let t=kn({x:s.from.x+e.clientX-s.start.x,y:s.from.y+e.clientY-s.start.y},a);g({sheet:t}),u(En(t.x,a))},Pe=e=>{s&&e.pointerId===s.pointer&&(c(null),u(null),l&&Oe(l))};K(()=>{if(!s)return;let e=e=>{e.key===`Escape`&&(g({sheet:s.from}),c(null),u(null))};return document.addEventListener(`keydown`,e,!0),()=>document.removeEventListener(`keydown`,e,!0)},[s,g]);let Ie=X(`div`,{class:`sr-only`,role:`status`,"aria-live":`polite`,children:d});if(r.mode===`hidden`)return X(`div`,{class:`lens`,children:[X(`button`,{ref:p,type:`button`,class:`reopen`,"data-side":r.side,"aria-label":J(`Show Lens`,`taw-core`),title:J(`Show Lens`,`taw-core`),onClick:je,children:X(qe,{})}),Ie]});let Y=r.mode===`docked`,Le=Y?{"--lens-top":`${a.top}px`}:{left:`${De.x}px`,top:`${De.y}px`,height:`${Dn(a)}px`};return X(`div`,{class:`lens`,children:[l&&X(`div`,{class:`dock-zone`,"data-side":l,"aria-hidden":`true`,style:{"--lens-top":`${a.top}px`},children:X(`span`,{class:`dock-zone-label`,children:J(l===`left`?`Release to dock left`:`Release to dock right`,`taw-core`)})}),X(`aside`,{class:`panel`,"aria-label":J(`TAW Lens`,`taw-core`),"data-mode":r.mode,"data-side":r.side,"data-dragging":s?``:void 0,style:Le,children:[X(`div`,{class:`header`,onPointerDown:Me,onPointerMove:Ne,onPointerUp:Pe,onPointerCancel:Pe,children:[X(`div`,{class:`brand`,children:[Y?X(qe,{}):X(`span`,{class:`grip`,children:X(it,{})}),X(`span`,{class:`brand-name`,children:J(`Lens`,`taw-core`)}),Y&&e&&G&&X(`span`,{class:`brand-host`,children:e}),P===`connecting`?X(`span`,{class:`status`,children:[X(ht,{size:12}),J(`Connecting…`,`taw-core`)]}):P===`connected`?null:X(`span`,{class:`status`,children:[X(`span`,{class:`status-dot`,"aria-hidden":`true`}),J(P===`outdated`?`Restart needed`:`Not connected`,`taw-core`)]})]}),X(`div`,{class:`tools`,children:[X(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Pick an element`,`taw-core`),title:J(x?`Stop picking (Esc)`:`Pick an element`,`taw-core`),"aria-pressed":x,"aria-disabled":_?void 0:`true`,onClick:()=>_&&S(!x),children:X(Je,{})}),X(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Structure tree`,`taw-core`),title:J(`Structure tree`,`taw-core`),"aria-pressed":C===`tree`,"aria-disabled":_?void 0:`true`,onClick:()=>_&&pe(),children:X(Ye,{})}),X(`button`,{type:`button`,class:`icon-button`,"aria-label":jr(r.theme),title:jr(r.theme),onClick:()=>g({theme:or(r.theme)}),children:X(Mr,{theme:r.theme})}),Y?X(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Undock into a sheet`,`taw-core`),title:J(`Undock into a sheet`,`taw-core`),onClick:ke,children:X(Xe,{})}):X(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Dock as Inspector`,`taw-core`),title:J(`Dock as Inspector`,`taw-core`),onClick:()=>Oe(An(De,a)),children:X(Ze,{})}),X(`button`,{ref:m,type:`button`,class:`icon-button`,"aria-label":J(`Hide Lens`,`taw-core`),title:J(`Hide Lens`,`taw-core`),onClick:Ae,children:Y?X(Qe,{side:r.side}):X($e,{})})]})]}),X(`div`,{class:`body`,ref:ie,onScroll:se,children:C===`tree`?de?X(Dr,{root:de,selected:fe,reveal:j,onHover:e=>A(e?{target:e,label:!1}:null),onSelect:he,onBack:()=>w(`chat`)}):X(`div`,{class:`empty`,children:[X(`div`,{class:`empty-title`,children:J(`No source map on this page`,`taw-core`)}),X(`div`,{class:`empty-text`,children:J(`Lens maps what TAW renders. Reload the page; if it stays empty, check that the theme calls wp_footer().`,`taw-core`)})]}):F.turns.length>0||F.busy&&G?X(It,{chat:F,connection:P,onAnswer:(e,t)=>void Se(e,t),onRetry:xe,onTakeOver:we}):P===`connecting`?X(Pt,{}):P===`connected`?U?X(`div`,{class:`empty`,children:X(`div`,{class:`empty-text`,children:J(`No conversation yet. Select something on the page, or tell Claude what to change.`,`taw-core`)})}):X(`div`,{class:`empty`,children:[X(Je,{size:22}),X(`div`,{class:`empty-title`,children:J(`Point at anything on the page`,`taw-core`)}),X(`div`,{class:`empty-text`,children:J(`Click an element to see what TAW knows about it, or open the structure tree. Then tell Claude what to change.`,`taw-core`)}),X(`div`,{class:`gestures`,children:[X(`span`,{children:[X(`span`,{class:`kbd`,children:J(`Click`,`taw-core`)}),` `,J(`select`,`taw-core`)]}),X(`span`,{children:[X(`span`,{class:`kbd`,children:J(`⇧ Click`,`taw-core`)}),` `,J(`add to selection`,`taw-core`)]}),X(`span`,{children:[X(`span`,{class:`kbd`,children:J(`Esc`,`taw-core`)}),` `,J(`stop picking`,`taw-core`)]})]})]}):X(Nt,{outdated:P===`outdated`})}),C===`chat`&&N&&U&&b&&_&&X(Tn,{manifest:b.manifest,target:U,editor:_.editor,onClose:ue,onShowInTree:me}),T.length>0&&(C===`tree`||T.length>1||!N)&&X(`div`,{class:`chips`,role:`list`,"aria-label":J(`Selection`,`taw-core`),children:[T.map(e=>X(`span`,{role:`listitem`,children:X(`button`,{type:`button`,class:`chip mono`,"data-primary":T.length>1&&e.key===U?.key?``:void 0,onMouseEnter:()=>A({target:e,label:!1}),onMouseLeave:()=>A(null),onFocus:()=>A({target:e,label:!1}),onBlur:()=>A(null),onClick:()=>{O(e.key),ne(!0),w(`chat`)},children:b?Xt(b.manifest,e):e.key})},e.key)),T.length===1&&X(`span`,{class:`chips-hint`,children:J(`Shift-click to add more`,`taw-core`)})]}),X(`div`,{class:`composer`,children:[X(`label`,{class:`sr-only`,for:`lens-message`,children:J(`Message`,`taw-core`)}),X(`div`,{class:`composer-box`,"data-disabled":G&&!F.working?void 0:``,children:[X(`textarea`,{id:`lens-message`,ref:oe,class:`composer-input`,rows:1,value:L,disabled:!G||F.working,placeholder:P===`off`||P===`outdated`?J(`Start Lens to chat`,`taw-core`):F.working?J(`Claude is working…`,`taw-core`):J(`Tell Claude what to change…`,`taw-core`),onInput:e=>R(e.currentTarget.value),onKeyDown:Te}),F.working&&G?X(`button`,{type:`button`,class:`stop`,onClick:Ce,children:[X(vt,{}),J(`Stop`,`taw-core`)]}):X(`button`,{type:`button`,class:`send`,"aria-label":J(`Send`,`taw-core`),disabled:!G||L.trim()===``,onClick:()=>void be(),children:X(et,{})})]})]})]}),X(Zn,{rings:Ee,sampler:_?.sampler??null,top:a.top,area:Mn(a,r.mode===`docked`?r.side:null),refresh:M}),Ie]})}var Pr=`taw-lens-pick`,$=24;function Fr(e,t){try{let n=e.createElement(`canvas`);n.width=$,n.height=$;let r=n.getContext(`2d`,{willReadFrequently:!0});return r?(r.drawImage(t,0,0,$,$),{data:r.getImageData(0,0,$,$).data}):null}catch{return null}}function Ir(e,t,n,r){if(!e||t.width<=0||t.height<=0)return null;let i=Math.min(23,Math.max(0,Math.floor((n-t.left)/t.width*$))),a=(Math.min(23,Math.max(0,Math.floor((r-t.top)/t.height*$)))*$+i)*4,o=e.data;return{r:o[a],g:o[a+1],b:o[a+2],a:o[a+3]/255}}function Lr(e,t,n){let r=e.defaultView??window,i=new WeakMap,a=new Map,o=new Map,s,c=t=>{if(s===void 0&&(s=e.createElement(`canvas`).getContext(`2d`,{willReadFrequently:!0})),!s)return null;s.clearRect(0,0,1,1),s.fillStyle=`#000`,s.fillStyle=t,s.fillRect(0,0,1,1);let[n,r,i,a]=s.getImageData(0,0,1,1).data;return{r:n,g:r,b:i,a:a/255}};return{stackAt:(n,r)=>e.elementsFromPoint(n,r).filter(e=>e!==t&&!t.contains(e)),style:e=>r.getComputedStyle(e),color(e){return o.has(e)||o.set(e,Hn(e,c)),o.get(e)??null},media(t,n,r){let a=t,o=t.currentSrc||t.src||`canvas`;if(t.tagName===`IMG`&&!t.complete)return null;let s=i.get(t);return(!s||s.key!==o)&&(s={key:o,pixels:Fr(e,a)},i.set(t,s)),Ir(s.pixels,t.getBoundingClientRect(),n,r)},backgroundImage(t,r,i,o){let s=a.get(r);if(!s){let t=new Image,i={image:t,pixels:null,ready:!1};t.crossOrigin=`anonymous`,t.onload=()=>{i.pixels=Fr(e,t),i.ready=!0,n()},t.onerror=()=>{i.ready=!0},t.src=new URL(r,e.baseURI).href,a.set(r,i),s=i}return s.ready?Ir(s.pixels,t.getBoundingClientRect(),i,o):null}}}var Rr=[`pointerdown`,`pointerup`,`mousedown`,`mouseup`,`click`,`dblclick`,`auxclick`,`touchstart`];function zr(e,t,n){let r=e.defaultView??window,i=e=>e.composedPath().includes(t),a=n=>{let r=n.target;return!(r instanceof Element)||r===t||t.contains(r)||r===e.documentElement?null:r},o=e=>n.hover(i(e)?null:a(e)),s=e=>{e.relatedTarget||n.hover(null)},c=e=>{if(!i(e)&&(e.preventDefault(),e.stopImmediatePropagation(),e.type===`click`)){let t=a(e);t&&n.pick(t,e.shiftKey)}},l=e=>{e.key===`Escape`&&(e.preventDefault(),e.stopImmediatePropagation(),n.stop())},u=e.getElementById(Pr);u||(u=e.createElement(`style`),u.id=Pr,u.textContent=`html, html * { cursor: crosshair !important; }`,e.head.appendChild(u)),r.addEventListener(`pointermove`,o,!0),e.addEventListener(`mouseout`,s,!0);for(let e of Rr)r.addEventListener(e,c,{capture:!0,passive:!1});return r.addEventListener(`keydown`,l,!0),()=>{r.removeEventListener(`pointermove`,o,!0),e.removeEventListener(`mouseout`,s,!0);for(let e of Rr)r.removeEventListener(e,c,{capture:!0});r.removeEventListener(`keydown`,l,!0),e.getElementById(Pr)?.remove(),n.hover(null)}}function Br(e,t){let n=Bt(e);return n?new gn(e,n,t):null}var Vr={surface:`#FFFFFF`,line:`#ECEDF0`,text:`#1A1C21`,muted:`#5F6672`,mutedSoft:`#6B7280`,bubble:`#F4F5F7`,chip:`#F0F1F3`,accent:`#1A1C21`,onAccent:`#FFFFFF`,buttonLine:`#DDE0E5`,composerLine:`#E3E5E9`,amberLine:`#F0DDB6`,amberFill:`#FFFBF3`,amberDot:`#C98512`,amberText:`#6B5A35`,amberRule:`#F3E7CC`,diffRemovedFill:`#FDEEEF`,diffRemovedText:`#9F2430`,diffAddedFill:`#EAF6EF`,diffAddedText:`#1C6B3E`},Hr={surface:`#17191E`,line:`#262A31`,text:`#E7E9ED`,muted:`#9AA1AD`,mutedSoft:`#9AA1AD`,bubble:`#22252C`,chip:`#262A31`,accent:`#E7E9ED`,onAccent:`#17191E`,buttonLine:`#3A3F4B`,composerLine:`#30343C`,amberLine:`#4A3D22`,amberFill:`#1E1A12`,amberDot:`#E0A33E`,amberText:`#C9B68E`,amberRule:`#3A3122`,diffRemovedFill:`#3A1F24`,diffRemovedText:`#F4B9BF`,diffAddedFill:`#173023`,diffAddedText:`#A6E0BE`},Ur={dockedWidth:`400px`,sheetWidth:`400px`,sheetHeight:`600px`,sheetRadius:`12px`,sheetShadow:`0 1px 2px rgba(20,24,33,.05), 0 12px 32px rgba(20,24,33,.08)`,fontUi:`Inter, system-ui, -apple-system, "Segoe UI", sans-serif`,fontMono:`"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace`},Wr=e=>`--lens-${e.replace(/[A-Z]/g,e=>`-${e.toLowerCase()}`)}`,Gr=e=>Object.entries(e).map(([e,t])=>`${Wr(e)}: ${t};`).join(` `);function Kr(){return[`:host { ${Gr(Vr)} ${Gr(Ur)} color-scheme: light; }`,`@media (prefers-color-scheme: dark) { :host(:not([data-theme="light"])) { ${Gr(Hr)} color-scheme: dark; } }`,`:host([data-theme="dark"]) { ${Gr(Hr)} color-scheme: dark; }`].join(`
`)}var qr=String.raw,Jr=()=>Kr()+qr`
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
        .approval-file {
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

        @media (prefers-reduced-motion: no-preference) {
            .icon-button,
            .send,
            .reopen {
                transition:
                    background-color 0.12s ease,
                    color 0.12s ease;
            }
        }
    `,Yr=`taw-lens`,Xr=`taw-lens-push`,Zr=`vscode://file/{path}:{line}`;function Qr(e,t,n=Zr){let r=e.defaultView??window,i=new Set,a;return{map(n=!1){return(n||a===void 0)&&(a=Br(e,t)),a},pick:n=>zr(e,t,n),sampler:Lr(e,t,()=>i.forEach(e=>e())),onAsset(e){return i.add(e),()=>i.delete(e)},reveal(e){let t=e.getBoundingClientRect();(t.bottom<0||t.top>r.innerHeight)&&e.scrollIntoView({block:`center`,behavior:`smooth`})},editor:n,url:r.location.href}}function $r(e,t){let n=e.defaultView??window,r=window.tawLens;return{viewport(){let t=e.getElementById(`wpadminbar`),r=t&&n.getComputedStyle(t).position===`fixed`?Math.max(0,t.getBoundingClientRect().bottom):0;return{width:e.documentElement.clientWidth||n.innerWidth,height:n.innerHeight,top:r}},push(t){let n=e.getElementById(Xr);if(t===null){n?.remove();return}n||(n=e.createElement(`style`),n.id=Xr,e.head.appendChild(n)),n.textContent=jn(t)},theme(e){e===`system`?t.removeAttribute(`data-theme`):t.setAttribute(`data-theme`,e)},save:e=>ir(e),onResize(e){return n.addEventListener(`resize`,e),()=>n.removeEventListener(`resize`,e)},page:Qr(e,t,window.tawLens?.editor||Zr),bridge:r?.bridge?e=>z({bridge:r.bridge,nonce:r.nonce??``,protocol:1},e):void 0}}function ei(e=document){let t=e.querySelector(Yr);if(t)return t;let n=e.createElement(Yr);e.documentElement.appendChild(n);let r=n.attachShadow({mode:`open`}),i=e.createElement(`style`);i.textContent=Jr(),r.appendChild(i);let a=e.createElement(`div`);r.appendChild(a);let o=e.defaultView?.location.host??``;return re(X(Nr,{host:window.tawLens?.host||o,initial:rr(),env:$r(e,n)}),a),n}typeof document<`u`&&(document.readyState===`loading`?document.addEventListener(`DOMContentLoaded`,()=>ei(),{once:!0}):ei());
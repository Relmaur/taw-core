var e,t,n,r,i,a,o,s,c,l,u={},d=[],f=/^m(i|n|o|s|text|space)$/,p=Array.isArray,m=d.slice,h=Object.assign;function g(e){e&&e.parentNode&&e.remove()}function _(n,r,i,a,o){var s={type:n,props:r,key:i,ref:a,__k:null,__:null,__b:0,__e:null,__c:null,constructor:void 0,__v:o||++t,__i:-1,__u:0};return!o&&e.vnode&&e.vnode(s),s}function v(e){return e.children}function y(e,t){this.props=e,this.context=t,this.__g=0}function b(e,t){if(t==null)return e.__?b(e.__,e.__i+1):null;for(var n;t<e.__k.length;t++)if((n=e.__k[t])&&n.__e)return n.__e;return typeof e.type!=`function`||e.props.__P?null:b(e)}function x(e){if((e=e.__)&&e.__c&&!e.props.__P)return e.__e=null,e.__k.some(function(t){return t&&(e.__e=t.__e)}),x(e)}function S(t){(8&t.__g||!(t.__g|=8)||!n.push(t)||i++)&&r==e.debounceRendering||((r=e.debounceRendering)||queueMicrotask)(C)}function C(){var t,r,o,s,c,l,u,d,f;try{for(r=1;n.length;)n.length>r&&n.sort(a),t=n.shift(),r=n.length,8&t.__g&&(s=void 0,c=void 0,l=(c=(o=t).__v).__e,u=[],d=[],(f=o.__P)&&((s=h({constructor:void 0},c)).__v=c.__v+1,e.vnode&&e.vnode(s),j(f,s,c,o.__n,f.namespaceURI,32&c.__u?[l]:null,u,l||b(c),32&c.__u,d),s.__v=c.__v,s.__.__k[s.__i]=s,M(u,s,d),c.__=c.__e=null,s.__e!=l&&x(s)))}finally{n.length=i=0}}function w(e,t,n,r,i,a,o,s,c,l,f){var p,m,h,g,_,v,y=r.__k||d,b=t.length;for(c=T(n,t,y,c,b),p=0;p<b;p++)(h=n.__k[p])!=null&&(m=~h.__i&&y[h.__i]||u,h.__i=p,v=j(e,h,m,i,a,o,s,c,l,f),g=h.__e,(m.ref!=h.ref||8&m.__u)&&(m.ref&&N(m.ref,null,h,m),h.ref&&f.push(h.ref,h.__c||g,h)),_||=g,4&h.__u?(c=E(h,c,e,!m.__v),m.__e&&(m.__e=null)):typeof h.type==`function`&&v!==void 0?c=v:g&&(c=g.nextSibling),h.__u&=-7);return n.__e=_,c}function T(e,t,n,r,i){var a,o,s,c,l,u,d,f,m,h,g=n.length,y=g,x=0,S=!1,C=e.__k=Array(i);for(a=0;a<i;a++)(o=t[a])!=null&&typeof o!=`boolean`&&typeof o!=`function`?(typeof o!=`object`||o.constructor==String?o=C[a]=_(null,o):p(o)?o=C[a]=_(v,{children:o}):o.constructor===void 0&&o.__b?o=C[a]=_(o.type,o.props,o.key,o.ref,o.__v):C[a]=o,c=a+x,o.__=e,o.__b=e.__b+1,s=null,~(l=o.__i=D(o,n,c,y))&&(y--,(s=n[l])&&(s.__u|=2)),s&&s.__v?(o.__u|=2,l==c-1?x--:l==c+1?x++:l!=c&&(l>c?x--:x++,S=!0)):(~l||(i>g?x--:i<g&&x++),typeof o.type!=`function`&&(o.__u|=4))):C[a]=null;if(S){for(u=[],d=[],a=0;a<i;a++)if((o=C[a])&&2&o.__u){for(f=0,m=u.length;f<m;)u[h=f+m>>1]<o.__i?f=h+1:m=h;u[f]=o.__i,d[a]=f+1}for(x=u.length;a--;)d[a]&&(d[a]==x?x--:C[a].__u|=4)}if(y)for(a=0;a<g;a++)!(s=n[a])||2&s.__u||(s.__e==r&&(r=b(s)),ne(s,s));return r}function E(e,t,n,r){var i,a,o;if(typeof e.type==`function`){if(e.props.__P)return t;if(i=e.__k)for(a=0;a<i.length;a++)i[a]&&(i[a].__=e,t=E(i[a],t,n,!1));return t}for(t&&!t.parentNode&&(t=b(e))&&!t.parentNode&&(t=null),o=t;o&&o.nodeType==8;)o=o.nextSibling;for(e.__e!=o&&(!r&&n.moveBefore&&e.__e.parentNode?n.moveBefore(e.__e,t):n.insertBefore(e.__e,t||null)),t=e.__e;(t&&=t.nextSibling)&&t.nodeType==8;);return t}function D(e,t,n,r){var i,a,o,s=e.key,c=e.type,l=t[n],u=l&&!(2&l.__u);if(l===null&&s==null||u&&s==l.key&&c==l.type)return n;if(r>+!!u){for(i=n-1,a=n+1;i>=0||a<t.length;)if((l=t[o=i>=0?i--:a++])&&!(2&l.__u)&&s==l.key&&c==l.type)return o}return-1}function O(e,t,n){n??=``,t[0]==`-`?e.setProperty(t,n):e[t]=n}function k(e,t,n,r,i){var a,o;n:if(t==`style`){if(typeof n==`string`)e.style.cssText=n;else{if(typeof r==`string`&&(e.style.cssText=r=``),r)for(t in r)n&&t in n||O(e.style,t,``);if(n)for(t in n)r&&n[t]==r[t]||O(e.style,t,n[t])}}else if(t[0]==`o`&&t[1]==`n`)(e.__e||={})[t]=n,n&&r||(o=l[t]||(l[t]=A(t)),(e.__a||={})[t]=c,a=t!=(t=t.replace(s,`$1`)),(t=t.slice(2))[0]<`a`&&(t=t.toLowerCase()),n?e.addEventListener(t,o,a):e.removeEventListener(t,o,a));else{if(i==`http://www.w3.org/2000/svg`)t=t.replace(/xlink(H|:h)/,`h`).replace(/sName$/,`s`);else if(t!=`width`&&t!=`height`&&t!=`href`&&t!=`list`&&t!=`form`&&t!=`tabIndex`&&t!=`download`&&t!=`rowSpan`&&t!=`colSpan`&&t!=`role`&&t!=`popover`&&t in e)try{e[t]=n??``;break n}catch{}typeof n==`function`||(n==null||!1===n&&t[4]!=`-`?e.removeAttribute(t):e.setAttribute(t,t==`popover`&&n==1?``:n))}}function A(t){return function(n){if(this.__e){var r=this.__e[t];if(n[o]==null)n[o]=c++;else if(n[o]<this.__a[t])return;return r(e.event?e.event(n):n)}}}function j(t,n,r,i,a,o,s,c,l,u){var f,m,_,x,S,C,T,E,D,O,k,A,j,M,N,P,F,I,L,R,z=n.type;if(n.constructor!==void 0)return null;if(128&r.__u&&(l=32&r.__u,f=r.__c.__z)){if(n.__u|=l,m=o=[],f.nodeType==8)for(_=1,x=f.nextSibling;x;x=x.nextSibling){if(x.nodeType==8){if(x.data.startsWith(`$s`))_++;else if(x.data.startsWith(`/$s`)&&!--_)break}o.push(x)}else o.push(f);c=o[0]}(f=e.__b)&&f(n);n:if(typeof z==`function`){S=s.length;try{if(O=n.props,k=(f=z.prototype)&&f.render,A=(f=z.contextType)&&i[f.__c],j=f?A?A.props.value:f.__:i,r.__c?2&(C=n.__c=r.__c).__g&&(C.__g|=1):(k?n.__c=C=new z(O,j):(n.__c=C=new y(O,j),C.constructor=z,C.render=re),A&&A.sub(C),C.state||(C.state={}),C.__n=i,C.__g|=8,C.__h=[],C.__k=[]),k&&(C.__s||(C.__s=C.state),z.getDerivedStateFromProps&&(C.__s==C.state&&(C.__s=h({},C.__s)),h(C.__s,z.getDerivedStateFromProps(O,C.__s)))),T=C.props,E=C.state,C.__v=n,r.__c){if(k&&!z.getDerivedStateFromProps&&O!==T&&C.componentWillReceiveProps&&C.componentWillReceiveProps(O,j),n.__v==r.__v&&!(8&C.__g)||!(4&C.__g)&&C.shouldComponentUpdate&&!1===C.shouldComponentUpdate(O,C.__s,j)){n.__v!=r.__v&&(C.props=O,C.state=C.__s,C.__g&=-9),n.__e=r.__e,n.__k=r.__k,n.__k.some(function(e){e&&(e.__=n)}),d.push.apply(C.__h,C.__k),C.__k=[],C.__h.length&&s.push(C),c=b(r);break n}C.componentWillUpdate&&C.componentWillUpdate(O,C.__s,j),k&&C.componentDidUpdate&&C.__h.push(function(){C.componentDidUpdate(T,E,D)})}else k&&!z.getDerivedStateFromProps&&C.componentWillMount&&C.componentWillMount(),k&&C.componentDidMount&&C.__h.push(C.componentDidMount);if(C.context=j,C.props=O,C.__P=t,C.__g&=-5,M=e.__r,N=0,k)C.state=C.__s,C.__g&=-9,M&&M(n),f=C.render(C.props,C.state,C.context),d.push.apply(C.__h,C.__k),C.__k=[];else do C.__g&=-9,M&&M(n),f=C.render(C.props,C.state,C.context),C.state=C.__s;while(8&C.__g&&++N<25);C.state=C.__s,C.getChildContext&&(i=h({},i,C.getChildContext())),k&&r.__c&&C.getSnapshotBeforeUpdate&&(D=C.getSnapshotBeforeUpdate(T,E)),P=f&&f.type===v&&f.key==null?f.props.children:f,O.__P&&(f=c,a=(t=O.__P).namespaceURI,l=o=null,r.props&&r.props.__P!=t&&(r.__k.some(function(e){e&&ne(e,e)}),r.__k=null),c=r.__k?b(r,0):null),c=w(t,p(P)?P:[P],n,r,i,a,o,s,c,l,u),O.__P&&(n.__e=null,c=f),n.__u&=-161,128&r.__u&&(C.__z=null),m&&m.some(g),C.__h.length&&s.push(C),1&C.__g&&(C.__g&=-4)}catch(t){if(s.length=S,n.__v=null,l||o){if(t.then){if(F=0,n.__u|=l?160:128,o){for(~(L=o.indexOf(c||void 0))||(L=o.length);(R=o[L-1])&&R.nodeType==8;)L--;for(;L<o.length;L++)if(R=o[L]){if(o[L]=null,R.nodeType==8){if(R.data.startsWith(`$s`))F++||(I=R);else if(F&&R.data.startsWith(`/$s`)&&!--F){c=R;break}}else if(!F)break}}if(!I){for(;c&&c.nodeType==8&&c.nextSibling;)c=c.nextSibling;I=c}n.__c.__z||(n.__c.__z=I),n.__e=c}else o&&o.some(g)}else n.__e=r.__e;n.__k||=r.__k||[],t.then||ee(n),e.__e(t,n,r)}}else c=n.__e=te(r.__e,n,r,i,a,o,s,l,u,t);return(f=e.diffed)&&f(n),128&n.__u?void 0:c}function ee(e){e&&(e.__c&&(e.__c.__g|=4),e.__k&&e.__k.some(ee))}function M(t,n,r){for(var i=0;i<r.length;)N(r[i++],r[i++],r[i++]);e.__c&&e.__c(n,t),t.some(function(n){try{t=n.__h,n.__h=[],t.some(function(e){e.call(n)})}catch(t){e.__e(t,n.__v)}})}function te(t,n,r,i,a,o,s,c,l,d){var h,_,v,y,x,S,C,T,E,D=r.props||u,O=n.props,A=n.type;if(A==`svg`?a=`http://www.w3.org/2000/svg`:A==`math`?a=`http://www.w3.org/1998/Math/MathML`:a||=`http://www.w3.org/1999/xhtml`,o){for(h=0;h<o.length;h++)if((x=o[h])&&(A?x.localName==A:x.nodeType==3)){t=x,o[h]=null;break}}if(!t){if(T=d.ownerDocument||document,!A)return T.createTextNode(O);t=T.createElementNS(a,A,O.is&&O),c&&=(e.__m&&e.__m(n,o),!1),o=null}if(A){if(d=A==`template`?t.content:t,o=A==`textarea`&&O.defaultValue!=null?null:o&&m.call(d.childNodes),!c&&o)for(D={},h=0;h<t.attributes.length;h++)D[(x=t.attributes[h]).name]=x.value;for(h in D)x=D[h],h==`dangerouslySetInnerHTML`?v=x:h==`children`||h in O||h==`value`&&`defaultValue`in O||h==`checked`&&`defaultChecked`in O||k(t,h,null,x,a);for(h in E=1&r.__u,O)x=O[h],h==`children`?y=x:h==`dangerouslySetInnerHTML`?_=x:h==`value`?S=x:h==`checked`?C=x:c&&typeof x!=`function`||!(D[h]!==x||E&&x!=null)||k(t,h,x,D[h],a);_?(c||v&&(_.__html==v.__html||_.__html==t.innerHTML)||(t.innerHTML=_.__html),n.__k=[]):(v&&(t.textContent=``),(A==`foreignObject`||a==`http://www.w3.org/1998/Math/MathML`&&f.test(A))&&(a=`http://www.w3.org/1999/xhtml`),w(d,p(y)?y:[y],n,r,i,a,o,s,o?o[0]:r.__k&&b(r,0),c,l),o&&o.some(g)),c&&A!=`textarea`||(h=`value`,A==`progress`&&S==null?t.removeAttribute(h):S==null||S===t[h]&&(A!=`progress`||S)||k(t,h,S,D[h],a),h=`checked`,C!=null&&C!=t[h]&&k(t,h,C,D[h],a))}else D===O||c&&t.data==O||(t.data=O);return t}function N(t,n,r,i){try{typeof t==`function`?n?n.__x=t(n)||1:(i&&=i.__c||i.__e)&&(n=i.__x)&&(i.__x=null,typeof n==`function`?n():t(null)):t.current=n}catch(t){e.__e(t,r)}}function ne(t,n,r){var i,a;if(e.unmount&&e.unmount(t),!(i=t.ref)||i.current&&i.current!=t.__e||N(i,null,n,t),i=t.__c){if(i.componentWillUnmount)try{i.componentWillUnmount()}catch(t){e.__e(t,n)}i.__P=i.__n=null}if(i=t.__k)for(a=0;a<i.length;a++)i[a]&&ne(i[a],n,typeof t.type!=`function`||r&&!t.props.__P);(i=t.__e)&&(r||g(i),i.__e&&(i.__e=null)),t.__e=t.__c=t.__=null}function re(e,t,n){return this.constructor(e,n)}function P(t,n){var r,i,a,o;e.__&&e.__(t,n),n.nodeType==9&&(n=n.documentElement),i=(r=t&&32&t.__u)?null:n.__k,n.__k=_(v,{children:[t]}),a=[],o=[],j(n,n.__k,i||u,u,n.namespaceURI,i?null:n.firstChild?m.call(n.childNodes):null,a,i?i.__e:n.firstChild,r,o),M(a,n.__k,o),n.__k.props.children=null}e={__e:function(e,t,n,r){for(var a,o,s;t=t.__;)if((a=t.__c)&&!(1&a.__g)){a.__g|=4;try{if((o=a.constructor)&&o.getDerivedStateFromError&&(a.setState(o.getDerivedStateFromError(e)),s=8&a.__g),a.componentDidCatch&&(a.componentDidCatch(e,r||{}),s=8&a.__g),s)return void(a.__g|=2)}catch(t){e=t,s=0}}throw i=0,e}},t=0,y.prototype.setState=function(e,t){var n=this.__s;n&&n!=this.state||(n=this.__s=h({},this.state)),typeof e==`function`&&(e=e(h({},n),this.props)),e&&(h(n,e),this.__v&&(t&&this.__k.push(t),S(this)))},y.prototype.forceUpdate=function(e){this.__v&&(this.__g|=4,e&&this.__h.push(e),S(this))},y.prototype.render=v,n=[],i=0,a=function(e,t){return e.__v.__b-t.__v.__b},o=Symbol(),s=/(PointerCapture)$|Capture$/i,c=0,l={};var F,I,L,R,z=Object.is,B=0,ie=[],V=[],H=e,ae=H.__b,oe=H.__r,se=H.diffed,ce=H.__c,le=H.unmount,ue=H.__;function U(e,t){H.__h&&H.__h(I,e,B||t),B=0;var n=I.__H||(I.__H={__:[],__h:[]});return e>=n.__.length&&n.__.push({}),n.__[e]}function W(e){return B=1,G(xe,e)}function G(e,t,n){var r=U(F++,2);if(r.t=e,!r.__c&&(r.__=[n?n(t):xe(void 0,t),function(e){var t=r.__N?r.__N[0]:r.__[0],n=r.t(t,e);z(t,n)||(r.__N=[n,r.__[1]],r.__c.setState({}))}],r.__c=I,!I.__f)){I.__f=!0;var i=I.shouldComponentUpdate;I.shouldComponentUpdate=function(e,t,n){var r=this.__H;if(!r)return!0;var a=!1,o=this.props!=e;if(r.__.some(function(e){e.__N&&(a=!0,z(e.__[0],e.__N[0])||(o=!0))}),i){var s=i.call(this,e,t,n);return a?s||o:s}return!a||o}}return r.__}function K(e,t){var n=U(F++,3);!H.__s&&be(n.__H,t)&&(n.__P=!0,n.__=e,n.u=t,I.__H.__h.push(n))}function de(e,t){var n=U(F++,4);!H.__s&&be(n.__H,t)&&(n.__P=!1,n.__=e,n.u=t,I.__h.push(n))}function q(e){return B=5,fe(function(){return{current:e}},[])}function fe(e,t){var n=U(F++,7);return be(n.__H,t)&&(n.__=e(),n.__H=t),n.__}function pe(e,t){return B=8,fe(function(){return e},t)}function me(){var e;do{for(;e=V.shift();)try{ve(e)}catch(t){H.__e(t,{__:(e=e.__P)&&e.__v})}for(;e=ie.shift();){var t=e.__H;if(e.__P&&t)try{t.__h.some(ve),t.__h.some(ye),t.__h=[]}catch(n){t.__h=[],H.__e(n,e.__v)}}}while(V.length)}H.__b=function(e){I=null,ae&&ae(e)},H.__=function(e,t){e&&t.__k&&t.__k.__m&&(e.__m=t.__k.__m),ue&&ue(e,t)},H.__r=function(e){oe&&oe(e),F=0;var t=(I=e.__c).__H;t&&(L==I?I.__h=[]:(t.__h.some(ve),t.__h.some(ye),F=0),t.__h=[],t.__.some(function(e){e.__N&&(e.__=e.__N),e.u=e.__N=void 0})),L=I},H.diffed=function(e){se&&se(e);var t=e.__c;t&&t.__H&&(t.__H.__h.length&&_e(ie.push(t)),t.__H.__.some(function(e){e.u&&(e.__H=e.u)})),L=I=null},H.__c=function(e,t){t.some(function(e){try{e.__h.some(ve),e.__h=e.__h.filter(function(e){return!e.__||ye(e)})}catch(n){t.some(function(e){e.__h&&=[]}),t=[],H.__e(n,e.__v)}}),ce&&ce(e,t)},H.unmount=function(e){le&&le(e);var t,n,r=e.__c;r&&r.__H&&(r.__H.__.some(function(r){try{if(r.__P&&r.__c){if(n===void 0){for(n=e.__;n&&(!n.__c||!n.__c.__P);)n=n.__;n&&=n.__c}r.__P=n,_e(V.push(r))}else ve(r)}catch(e){t=e}}),r.__H=void 0,t&&H.__e(t,r.__v))};var he=typeof requestAnimationFrame==`function`;function ge(e){var t,n=function(){clearTimeout(r),he&&cancelAnimationFrame(t),setTimeout(e)},r=setTimeout(n,35);he&&(t=requestAnimationFrame(n))}function _e(e){e!=1&&R==H.requestAnimationFrame||((R=H.requestAnimationFrame)||ge)(me)}function ve(e){var t=I,n=e.__c;typeof n==`function`&&(e.__c=void 0,n()),I=t}function ye(e){var t=I;e.__c=e.__(),I=t}function be(e,t){return!e||e.length!=t.length||t.some(function(t,n){return!z(t,e[n])})}function xe(e,t){return typeof t==`function`?t(e):t}var Se=()=>window.wp?.i18n,J=(e,t)=>Se()?.__(e,t)??e,Ce=`taw-lens-map`,we=e=>typeof e==`object`&&!!e&&!Array.isArray(e);function Te(e){if(!e)return null;let t;try{t=JSON.parse(e)}catch{return null}if(!we(t)||t.version!==1)return null;let n=Array.isArray(t.nodes)?t.nodes:we(t.nodes)?Object.values(t.nodes):[],r=new Map;for(let e of n)we(e)&&typeof e.id==`number`&&r.set(e.id,{...e,reads:Array.isArray(e.reads)?e.reads:[]});return r.has(0)?{version:1,nodes:r,files:we(t.files)?t.files:{}}:null}var Ee=e=>Te(e.getElementById(Ce)?.textContent);function De(e,t){let n=[],r=new Set;for(let i=e.nodes.get(t);i&&!r.has(i.id);)n.push(i),r.add(i.id),i=i.parent===null?void 0:e.nodes.get(i.parent);return n}var Y=(e,t)=>e.length>t?`${e.slice(0,t-1).trimEnd()}…`:e,Oe=e=>e.store===`wp`||e.store===`menu`||e.type===`wp`;function ke(e){switch(e){case`wysiwyg`:return J(`rich text`,`taw-core`);case`textarea`:return J(`long text`,`taw-core`);case`url`:case`link`:return J(`link`,`taw-core`);case`image`:return J(`image`,`taw-core`);case`repeater`:return J(`repeater`,`taw-core`);case`number`:return J(`number`,`taw-core`);case`select`:case`radio`:return J(`choice`,`taw-core`);default:return J(`text`,`taw-core`)}}function Ae(e,t=e.key){return e.store===`menu`?`${J(`Menu item`,`taw-core`)} “${Y(String(e.value??``),32)}”`:Oe(e)?e.key===`post_title`?J(`Post title`,`taw-core`):e.label:t}var je=e=>e.kind===`field`?Ae(e.read,e.path):`“${Y(e.text,40)}”`,Me=e=>e.label||e.name;function X(e,t,n){let r=De(e,t).reverse().map(Me).filter(e=>e!==``);return n&&r.push(n),r.join(` › `)}function Ne(e,t){let n=De(e,t).find(e=>e.kind!==`page`)??e.nodes.get(0);return n?n.name:``}function Pe(e,t){let n=t.attribution,r=Ne(e,n.kind===`block`?n.node:t.at),i=n.kind===`field`||n.kind===`template`?je(n.source):n.kind===`ambiguous`?J(`ambiguous`,`taw-core`):n.kind===`mixed`?J(`mixed`,`taw-core`):n.kind===`element`?t.elements[0]?.tagName.toLowerCase()??``:``;return i?`${r} › ${i}`:r}function Fe(e,t){let n=t.attribution;if(n.kind===`field`||n.kind===`template`)return je(n.source);if(n.kind===`block`){let t=e.nodes.get(n.node);return t?Me(t):``}return n.kind===`element`?`<${t.elements[0]?.tagName.toLowerCase()??`?`}>`:`“${Y(n.text,24)}”`}function Ie(e,t,n=1){return e.replace(`{path}`,encodeURI(t)).replace(`{line}`,String(n))}var Le=0;Array.isArray;function Z(t,n,r,i,a,o){n||={};var s,c,l=n;if(`ref`in l&&typeof t!=`function`)for(c in l={},n)c==`ref`?s=n[c]:l[c]=n[c];var u={type:t,props:l,key:r,ref:s,__k:null,__:null,__b:0,__e:null,__c:null,constructor:void 0,__v:--Le,__i:-1,__u:0};return(a||o)&&(u.__source=a,u.__self=o),e.vnode&&e.vnode(u),u}var Q=({size:e=16,width:t=1.8,children:n})=>Z(`svg`,{width:e,height:e,viewBox:`0 0 24 24`,fill:`none`,stroke:`currentColor`,"stroke-width":t,"stroke-linecap":`round`,"stroke-linejoin":`round`,"aria-hidden":`true`,focusable:`false`,children:n}),Re=e=>Z(Q,{...e,children:[Z(`circle`,{cx:`11`,cy:`11`,r:`6.5`}),Z(`path`,{d:`M16 16l4.5 4.5`})]}),ze=e=>Z(Q,{...e,children:Z(`path`,{d:`M5 3l14 7-6 2-2 6z`})}),Be=e=>Z(Q,{...e,children:Z(`path`,{d:`M4 5h6M8 12h6M8 19h6M6 5v14M6 12h2M6 19h2`})}),Ve=e=>Z(Q,{...e,children:[Z(`rect`,{x:`4`,y:`6`,width:`12`,height:`12`,rx:`2`}),Z(`path`,{d:`M13 3h8v8M21 3l-8 8`})]}),He=e=>Z(Q,{...e,children:[Z(`rect`,{x:`3`,y:`4`,width:`18`,height:`16`,rx:`2`}),Z(`path`,{d:`M15 4v16`})]}),Ue=({side:e,...t})=>Z(Q,{...t,children:Z(`path`,{d:e===`right`?`M9 6l6 6-6 6`:`M15 6l-6 6 6 6`})}),We=e=>Z(Q,{...e,children:Z(`path`,{d:`M6 12h12`})}),Ge=e=>Z(Q,{...e,width:2,children:Z(`path`,{d:`M12 19V5M6 11l6-6 6 6`})}),Ke=e=>Z(Q,{...e,children:[Z(`circle`,{cx:`12`,cy:`12`,r:`8`}),Z(`path`,{d:`M12 4a8 8 0 0 1 0 16z`,fill:`currentColor`})]}),qe=e=>Z(Q,{...e,children:[Z(`circle`,{cx:`12`,cy:`12`,r:`4`}),Z(`path`,{d:`M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4`})]}),Je=e=>Z(Q,{...e,children:Z(`path`,{d:`M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z`})}),Ye=({size:e=14})=>Z(`svg`,{width:e,height:e,viewBox:`0 0 24 24`,fill:`currentColor`,"aria-hidden":`true`,focusable:`false`,children:[Z(`circle`,{cx:`9`,cy:`6`,r:`1.6`}),Z(`circle`,{cx:`15`,cy:`6`,r:`1.6`}),Z(`circle`,{cx:`9`,cy:`12`,r:`1.6`}),Z(`circle`,{cx:`15`,cy:`12`,r:`1.6`}),Z(`circle`,{cx:`9`,cy:`18`,r:`1.6`}),Z(`circle`,{cx:`15`,cy:`18`,r:`1.6`})]}),Xe=({size:e=14})=>Z(Q,{size:e,width:2,children:Z(`path`,{d:`M6 6l12 12M18 6L6 18`})}),Ze=({size:e=12})=>Z(Q,{size:e,width:2,children:Z(`path`,{d:`M6 9l6 6 6-6`})}),Qe=({size:e=12})=>Z(Q,{size:e,width:2,children:Z(`path`,{d:`M9 6l6 6-6 6`})}),$e=({size:e=14})=>Z(Q,{size:e,children:[Z(`rect`,{x:`5`,y:`3`,width:`14`,height:`18`,rx:`2`}),Z(`path`,{d:`M9 8h6M9 12h6`})]}),et=({size:e=14})=>Z(Q,{size:e,children:[Z(`rect`,{x:`4`,y:`4`,width:`16`,height:`16`,rx:`2`}),Z(`path`,{d:`M4 9h16`})]}),tt=({size:e=14})=>Z(Q,{size:e,children:[Z(`rect`,{x:`4`,y:`4`,width:`7`,height:`7`,rx:`1`}),Z(`rect`,{x:`13`,y:`4`,width:`7`,height:`7`,rx:`1`}),Z(`rect`,{x:`4`,y:`13`,width:`16`,height:`7`,rx:`1`})]}),nt=({size:e=14})=>Z(Q,{size:e,children:Z(`path`,{d:`M5 7h14M5 12h10M5 17h7`})}),rt=({size:e=14})=>Z(Q,{size:e,children:Z(`path`,{d:`M6 6h12M12 6v12`})}),it=new Set([`checkbox`,`toggle`,`color`,`hidden`,`image`,`gallery`,`file`,`post_select`]),at=new Set([`SCRIPT`,`STYLE`,`NOSCRIPT`,`TEMPLATE`,`HEAD`,`TITLE`]),ot=new Set([`HTML`,`HEAD`,`BODY`]),st=4e3,ct=e=>e.replace(/\s+/g,` `).trim();function lt(e,t=document){let n=t.createElement(`template`);return n.innerHTML=e,ct(n.content.textContent??``)}var ut=(e,t)=>e===`wysiwyg`||/<[a-z][^>]*>/i.test(t);function dt(e){let t=e;if(typeof t==`string`&&/^\s*\[/.test(t))try{t=JSON.parse(t)}catch{return null}return Array.isArray(t)?t.filter(e=>typeof e==`object`&&!!e):null}var ft=new WeakMap;function pt(e,t=document){let n=ft.get(e);if(n)return n;let r=[];for(let n of e.reads){if(it.has(n.type))continue;let i=n.type===`repeater`||n.subs?dt(n.value):null;if(i){i.forEach((i,a)=>{for(let[o,s]of Object.entries(i)){if(typeof s!=`string`&&typeof s!=`number`)continue;let i=String(s),c=n.subs?.[o]?.type??`text`;if(!i.trim()||it.has(c))continue;let l=n.subs?.[o]?.label??o,u=ut(c,i);r.push({source:{kind:`field`,node:e.id,read:n,path:`${n.key}[${a}].${o}`,label:`${n.label} › ${l} (row ${a+1})`},text:u?lt(i,t):ct(i),rich:u})}});continue}if(typeof n.value!=`string`&&typeof n.value!=`number`)continue;let a=String(n.value);if(!a.trim())continue;let o=ut(n.type,a);r.push({source:{kind:`field`,node:e.id,read:n,path:n.key,label:n.label},text:o?lt(a,t):ct(a),rich:o})}return ft.set(e,r),r}function mt(e,t){let n=t.template;return n?(e.files[n]?.texts??[]).map(e=>({kind:`template`,node:t.id,file:n,line:e.line,text:ct(e.text),how:e.kind})):[]}function ht(e){return e.kind===`field`?`f:${e.read.store}:${e.read.object}:${e.path}`:`t:${e.file}:${e.line}:${e.text}`}var gt=e=>`f:${e.store}:${e.object}:${e.key}`;function _t(e){let t=new Map;for(let n of e){let e=ht(n);t.has(e)||t.set(e,n)}return[...t.values()]}function vt(e){let t=new Set(e.flatMap(e=>e.kind===`field`&&e.read.store===`menu`&&e.read.target?[e.read.target]:[]));return t.size===0?e:e.filter(e=>!(e.kind===`field`&&e.read.store===`post`&&e.read.key===`post_title`&&t.has(Number(e.read.object))))}function yt(e,t,n,r=document){let i=De(e,t);for(let t of i){let i=[],a=[];for(let e of pt(t,r))e.text===n?i.push(e.source):e.rich&&n.length>=2&&e.text.includes(n)&&a.push({...e.source,part:!0});for(let r of mt(e,t))r.text===n&&i.push(r);let o=_t(vt(i.length>0?i:a));if(o.length===1){let[e]=o;return e.kind===`field`?{kind:`field`,node:t.id,text:n,source:e}:{kind:`template`,node:t.id,text:n,source:e}}if(o.length>1)return{kind:`ambiguous`,node:t.id,text:n,sources:o}}for(let t of i){let i=[];for(let e of pt(t,r))e.text.length>=2&&n.includes(e.text)&&i.push(e.source);for(let r of mt(e,t))r.text.length>=2&&n.includes(r.text)&&i.push(r);if(i.length>0)return{kind:`mixed`,node:t.id,text:n,sources:_t(i)}}return{kind:`element`,node:t,text:n}}function bt(e){let t=``,n=e.ownerDocument.createTreeWalker(e,NodeFilter.SHOW_TEXT);for(let e=n.nextNode();e;e=n.nextNode())e.parentElement?.closest(`script,style,noscript,template`)||(t+=e.data);return ct(t)}var xt=class{manifest;doc;nodeByElement=new Map;roots=new Map;bySource=new Map;sourcesByKey=new Map;attributions=new Map;order=new Map;constructor(e,t,n){this.doc=e,this.manifest=t,this.scan(n??null)}nodeOf(e){return this.nodeByElement.get(e)??0}rootsOf(e){return this.roots.get(e)??[]}elementsOf(e){return this.bySource.get(e)??[]}sources(){return this.sourcesByKey}position(e){return this.order.get(e)??2**53-1}attribute(e){let t=this.attributions.get(e);if(t)return t;let n=this.compute(e);return this.attributions.set(e,n),n}isRoot(e){let t=this.nodeOf(e),n=e.parentElement;return t!==0&&(!n||ot.has(n.tagName)||this.nodeOf(n)!==t)}compute(e){if(ot.has(e.tagName))return{kind:`block`,node:0};let t=this.nodeOf(e);if(e.tagName===`IMG`){let n=this.imageField(e,t);if(n)return{kind:`field`,node:n.node,text:``,source:n}}let n=bt(e),r=n&&n.length<=st?yt(this.manifest,t,n,this.doc):null;if(r&&(r.kind===`field`||r.kind===`template`||r.kind===`ambiguous`))return r;if(this.isRoot(e))return{kind:`block`,node:t};let i=e.tagName===`A`?this.linkField(e,t):null;return i?{kind:`field`,node:i.node,text:n,source:i}:r?.kind===`mixed`?r:{kind:`element`,node:t,text:n}}imageField(e,t){let n=`${e.getAttribute(`src`)??``} ${e.getAttribute(`srcset`)??``}`;for(let e of De(this.manifest,t)){let t=e.reads.find(e=>e.type===`image`&&e.stem&&n.includes(`/${e.stem}`));if(t)return{kind:`field`,node:e.id,read:t,path:t.key,label:t.label}}return null}linkField(e,t){let n=e.getAttribute(`href`);if(!n)return null;for(let e of De(this.manifest,t)){let t=e.reads.find(e=>(e.type===`url`||e.type===`link`)&&typeof e.value==`string`&&e.value===n);if(t)return{kind:`field`,node:e.id,read:t,path:t.key,label:t.label}}return null}add(e,t){let n=this.bySource.get(e);n?n.includes(t)||n.push(t):this.bySource.set(e,[t])}scan(e){let t=[0],n=new Set,r=this.doc.createTreeWalker(this.doc,NodeFilter.SHOW_COMMENT|NodeFilter.SHOW_ELEMENT|NodeFilter.SHOW_TEXT),i=0;for(let a=r.nextNode();a;a=r.nextNode()){if(a.nodeType===Node.COMMENT_NODE){let e=/^(\/)?taw-lens:(\d+)$/.exec(a.data);if(e){if(e[1]){let n=t.lastIndexOf(Number(e[2]));n>0&&(t.length=n)}else t.push(Number(e[2]))}continue}if(a.nodeType===Node.ELEMENT_NODE){let n=a;if(e&&(n===e||e.contains(n)))continue;this.nodeByElement.set(n,t[t.length-1]),this.order.set(n,i++);continue}let r=a.parentElement;r&&ct(a.data)&&!at.has(r.tagName)&&!r.closest(`script,style,noscript,template,head`)&&!(e&&e.contains(r))&&n.add(r)}for(let[e,t]of this.nodeByElement)if(t!==0&&this.isRoot(e)&&!at.has(e.tagName)&&e.tagName!==`LINK`&&e.tagName!==`META`){let n=this.roots.get(t)??[];n.push(e),this.roots.set(t,n)}for(let[e,t]of this.nodeByElement)if(t===0&&!ot.has(e.tagName)&&!at.has(e.tagName)&&e.parentElement){let t=e.parentElement;if(ot.has(t.tagName)||this.nodeOf(t)!==0){let t=this.roots.get(0)??[];t.push(e),this.roots.set(0,t)}}let a=[...new Set([...n,...this.doc.querySelectorAll(`img, a[href]`)])].filter(t=>!(e&&e.contains(t)));for(let e of a){let t=this.attribute(e),n=t.kind===`field`||t.kind===`template`?[t.source]:t.kind===`ambiguous`?t.sources:[];for(let t of n){let n=ht(t);this.add(n,e),this.sourcesByKey.has(n)||this.sourcesByKey.set(n,t),t.kind===`field`&&this.add(gt(t.read),e)}}}};function St({title:e,mono:t,badge:n,onClose:r}){return Z(`div`,{class:`card-head`,children:[Z(`span`,{class:t?`card-title mono`:`card-title`,children:e}),Z(`span`,{class:`card-badge`,children:n}),r&&Z(`button`,{type:`button`,class:`card-close`,"aria-label":J(`Clear selection`,`taw-core`),onClick:r,children:Z(Xe,{})})]})}var Ct=({children:e})=>Z(`div`,{class:`card-actions`,children:e}),wt=()=>Z(`button`,{type:`button`,class:`card-button primary`,"aria-disabled":`true`,title:J(`Editing in place is coming in a later build`,`taw-core`),children:J(`Edit here`,`taw-core`)}),Tt=({href:e,children:t,primary:n})=>Z(`a`,{class:n?`card-button primary`:`card-button`,href:e,target:`_blank`,rel:`noopener noreferrer`,children:t});function Et(e){let t=e.read.value,n=/\[(\d+)\]\.(.+)$/.exec(e.path);if(n)try{let e=typeof t==`string`?JSON.parse(t):t;t=Array.isArray(e)?e[Number(n[1])]?.[n[2]]:t}catch{}else if(e.read.type===`repeater`)try{let e=typeof t==`string`?JSON.parse(t):t;if(Array.isArray(e))return e.length===1?J(`1 row`,`taw-core`):J(`%d rows`,`taw-core`).replace(`%d`,String(e.length))}catch{}if(e.read.type===`image`)return e.read.stem??String(t??``);let r=typeof t==`string`||typeof t==`number`?String(t):JSON.stringify(t??``);return Y(/<[a-z][^>]*>/i.test(r)?lt(r):r,600)}function Dt({manifest:e,sources:t,editor:n}){return Z(`ul`,{class:`card-sources`,children:t.map(t=>Z(`li`,{children:[Z(`span`,{class:`card-source-name mono`,children:je(t)}),Z(`span`,{class:`card-source-where`,children:t.kind===`field`?X(e,t.node,t.label):`${t.file}:${t.line}`}),t.kind===`field`?t.read.admin&&Z(`a`,{class:`card-link`,href:t.read.admin,target:`_blank`,rel:`noopener noreferrer`,children:J(`Open in wp-admin`,`taw-core`)}):Z(Ot,{manifest:e,source:t,editor:n})]},t.kind===`field`?t.path+t.read.object:`${t.file}:${t.line}`))})}function Ot({manifest:e,source:t,editor:n}){let r=e.files[t.file]?.abs;return r?Z(`a`,{class:`card-link`,href:Ie(n,r,t.line),children:J(`Open in editor`,`taw-core`)}):null}function kt({manifest:e,node:t}){let n=e.nodes.get(t)?.template;return n?Z(`div`,{class:`card-meta mono`,children:[J(`Rendered by`,`taw-core`),` `,n]}):null}function At({manifest:e,target:t,editor:n,onClose:r,onShowInTree:i}){let a=t.attribution,o=e.nodes.get(t.at);if(a.kind===`field`){let{read:n}=a.source,i=Oe(n),o=Ae(n,a.source.path),s=i?J(`WordPress`,`taw-core`):`${J(`Field`,`taw-core`)} · ${ke(n.subs?.[a.source.path.split(`.`).pop()??``]?.type??n.type)}`;return Z(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[Z(St,{title:o,mono:!i,badge:s,onClose:r}),Z(`div`,{class:`card-location`,children:X(e,a.source.node,a.source.label)}),Z(`div`,{class:`card-value`,children:Et(a.source)||Z(`span`,{class:`card-empty`,children:J(`(empty)`,`taw-core`)})}),n.default&&Z(`div`,{class:`card-note`,children:J(`The field’s default: nothing is saved yet.`,`taw-core`)}),a.source.part&&Z(`div`,{class:`card-note`,children:J(`Part of this rich-text field.`,`taw-core`)}),Z(kt,{manifest:e,node:t.at}),Z(Ct,{children:[i?null:Z(wt,{}),n.admin&&Z(Tt,{href:n.admin,children:J(`Open in wp-admin`,`taw-core`)})]})]})}if(a.kind===`template`){let{source:t}=a,i=e.files[t.file],o=i?.lines??{},s=[t.line-1,t.line,t.line+1].filter(e=>o[String(e)]!==void 0);return Z(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[Z(St,{title:`“${Y(t.text,60)}”`,badge:t.how===`i18n`?J(`Template text · translatable`,`taw-core`):J(`Template text`,`taw-core`),onClose:r}),Z(`div`,{class:`card-location`,children:X(e,t.node)}),s.length>0&&Z(`div`,{class:`card-code mono`,role:`group`,"aria-label":`${t.file}:${t.line}`,children:s.map(e=>Z(`div`,{class:`card-code-line`,"data-current":e===t.line?``:void 0,children:[Z(`span`,{class:`card-code-number`,children:e}),Z(`span`,{class:`card-code-text`,children:o[String(e)]})]},e))}),Z(`div`,{class:`card-meta mono`,children:[t.file,`:`,t.line]}),Z(Ct,{children:[Z(wt,{}),i?.abs&&Z(Tt,{href:Ie(n,i.abs,t.line),children:J(`Open in editor`,`taw-core`)})]})]})}if(a.kind===`block`){let o=e.nodes.get(a.node);if(!o)return null;let s=o.kind===`block`?`${J(`Block`,`taw-core`)} · ${o.name}`:o.kind===`part`?J(`Template part`,`taw-core`):J(`Page`,`taw-core`),c=o.files??(o.template?[o.template]:[]),l=[...new Set(o.reads.filter(e=>!Oe(e)&&e.store!==`option`).map(e=>e.key))],u=o.template?e.files[o.template]:void 0;return Z(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[Z(St,{title:Me(o),badge:s,onClose:r}),Z(`div`,{class:`card-location`,children:X(e,o.id)}),Z(`ul`,{class:`card-files mono`,children:c.map(t=>{let r=e.files[t]?.abs;return Z(`li`,{children:r?Z(`a`,{href:Ie(n,r),children:t}):t},t)})}),l.length>0&&Z(`div`,{class:`card-meta`,children:[J(`Fields:`,`taw-core`),` `,l.join(`, `)]}),Z(Ct,{children:[u?.abs&&Z(Tt,{href:Ie(n,u.abs),children:J(`Open in editor`,`taw-core`)}),Z(`button`,{type:`button`,class:`card-button`,onClick:()=>i(t),children:J(`Show in tree`,`taw-core`)})]})]})}if(a.kind===`ambiguous`)return Z(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[Z(St,{title:`“${Y(a.text,60)}”`,badge:J(`Ambiguous`,`taw-core`),onClose:r}),Z(`div`,{class:`card-location`,children:X(e,a.node)}),Z(`div`,{class:`card-note`,children:J(`More than one source holds this exact text, so Lens can’t tell which one rendered it. It doesn’t guess:`,`taw-core`)}),Z(Dt,{manifest:e,sources:a.sources,editor:n})]});if(a.kind===`mixed`)return Z(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[Z(St,{title:`“${Y(a.text,60)}”`,badge:J(`Mixed`,`taw-core`),onClose:r}),Z(`div`,{class:`card-location`,children:X(e,t.at)}),Z(`div`,{class:`card-note`,children:J(`Template text around values. Made of:`,`taw-core`)}),Z(Dt,{manifest:e,sources:a.sources,editor:n}),Z(kt,{manifest:e,node:t.at})]});let s=t.elements[0]?.tagName.toLowerCase()??``,c=o?.template?e.files[o.template]:void 0;return Z(`section`,{class:`card`,"aria-label":J(`Inspect`,`taw-core`),children:[Z(St,{title:`<${s}>`,mono:!0,badge:J(`Element`,`taw-core`),onClose:r}),Z(`div`,{class:`card-location`,children:X(e,t.at)}),Z(`div`,{class:`card-note`,children:a.text?J(`Its text isn’t a TAW field or template text: it may be computed in the template, or added by JavaScript.`,`taw-core`):J(`No text of its own. Its template rendered it.`,`taw-core`)}),Z(kt,{manifest:e,node:t.at}),Z(Ct,{children:c?.abs&&Z(Tt,{href:Ie(n,c.abs),children:J(`Open in editor`,`taw-core`)})})]})}function jt(e,t,n=400){return e<120?`left`:e+n>t.width-120?`right`:null}var Mt=e=>Math.max(240,Math.min(600,e.height-e.top-24));function Nt(e){return Pt({x:e.width-400-24,y:e.top+72},e)}function Pt(e,t,n=400){let r=Math.max(12,t.width-n-12),i=t.top+12,a=Math.max(i,t.height-Mt(t)-12);return{x:Math.round(Math.min(r,Math.max(12,e.x))),y:Math.round(Math.min(a,Math.max(i,e.y)))}}var Ft=(e,t,n=400)=>e.x+n/2<t.width/2?`left`:`right`;function It(e,t=400){return`html { margin-${e}: ${t}px !important; }`}function Lt(e,t,n=400){return{left:t===`left`?n:0,top:e.top,right:t===`right`?e.width-n:e.width,bottom:e.height}}function Rt(e,t,n){let r=Math.max(n.left+1,e.left-t),i=Math.max(n.top+1,e.top-t),a=Math.min(n.right-1,e.left+e.width+t),o=Math.min(n.bottom-1,e.top+e.height+t);return{left:r,top:i,width:Math.max(0,a-r),height:Math.max(0,o-i)}}function zt([e,t,n]){let r=e=>{let t=e/255;return t<=.04045?t/12.92:((t+.055)/1.055)**2.4};return .2126*r(e)+.7152*r(t)+.0722*r(n)}var Bt=`#1A1C21`,Vt=`#FFFFFF`,Ht=[26,28,33],Ut=[255,255,255];function Wt(e){let t=t=>{let[n,r]=e>t?[e,t]:[t,e];return(n+.05)/(r+.05)};return t(zt(Ht))>=t(zt(Ut))?{ring:Bt,halo:Vt,luminance:e}:{ring:Vt,halo:Bt,luminance:e}}var Gt=(e,t)=>[e.r*e.a+t[0]*(1-e.a),e.g*e.a+t[1]*(1-e.a),e.b*e.a+t[2]*(1-e.a)];function Kt(e){let t=Ut;for(let n=e.length-1;n>=0;n--)t=Gt(e[n],t);return t}function qt(e,t){let n=e.trim().toLowerCase();if(n===`transparent`||n===``)return{r:0,g:0,b:0,a:0};let r=/^#([0-9a-f]{3,8})$/.exec(n);if(r){let e=r[1],t=e.length<=4?[...e].map(e=>e+e).join(``):e;if(t.length===6||t.length===8){let e=e=>parseInt(t.slice(e,e+2),16);return{r:e(0),g:e(2),b:e(4),a:t.length===8?e(6)/255:1}}}let i=/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/.exec(n);if(i){let e=i[4]===void 0?1:i[4].endsWith(`%`)?parseFloat(i[4])/100:parseFloat(i[4]);return{r:+i[1],g:+i[2],b:+i[3],a:Math.max(0,Math.min(1,e))}}return t?t(e):null}function Jt(e){return e.match(/(?:rgba?|hsla?|oklch|oklab|lab|lch|color)\([^()]*\)|#[0-9a-f]{3,8}\b/gi)??[]}function Yt(e){if(e.length===0)return null;let t=e.reduce((e,t)=>({r:e.r+t.r,g:e.g+t.g,b:e.b+t.b,a:e.a+t.a}),{r:0,g:0,b:0,a:0}),n=e.length;return{r:t.r/n,g:t.g/n,b:t.b/n,a:t.a/n}}function Xt(e,t,n){let r=e.left-t,i=e.left+e.width+t,a=e.top-t,o=e.top+e.height+t,s=e.left+e.width/2,c=e.top+e.height/2;return[[r,a],[s,a],[i,a],[i,c],[i,o],[s,o],[r,o],[r,c]].map(([e,t])=>[Math.round(e),Math.round(t)]).filter(([e,t])=>e>=0&&t>=0&&e<n.width&&t<n.height)}var Zt=new Set([`IMG`,`VIDEO`,`CANVAS`]);function Qt(e,t,n){let r=[];for(let i of e.stackAt(t,n)){let a=e.style(i);if(a.visibility===`hidden`||parseFloat(a.opacity)===0)continue;if(Zt.has(i.tagName)){let a=e.media(i,t,n);if(a&&(r.push(a),a.a>=.999))break;continue}let o=a.backgroundImage;if(o&&o!==`none`){let a=/url\((['"]?)(.*?)\1\)/.exec(o)?.[2],s=(a?e.backgroundImage(i,a,t,n):null)??Yt(Jt(o).flatMap(t=>e.color(t)??[]));if(s&&s.a>0&&(r.push(s),s.a>=.999))break}let s=e.color(a.backgroundColor);if(s&&s.a>0&&(r.push(s),s.a>=.999))break}return Kt(r)}function $t(e,t,n,r){let i=Xt(t,n,r),a=(i.length>0?i:[[Math.round(t.left+t.width/2),Math.round(t.top+t.height/2)]]).map(([t,n])=>zt(Qt(e,t,n)));return Wt(a.reduce((e,t)=>e+t,0)/a.length)}var en={hover:4,selected:7},tn=24;function nn({rings:e,sampler:t,top:n,area:r,refresh:i=0}){let[a,o]=G(e=>e+1,0),s=q(new Map),c=e.length>0,l=q(i);K(()=>{if(!c)return;let e=0,t=0,n=()=>{e||=requestAnimationFrame(()=>{e=0,o(void 0)}),clearTimeout(t),t=window.setTimeout(()=>{s.current.clear(),o(void 0)},150)};window.addEventListener(`scroll`,n,{capture:!0,passive:!0}),window.addEventListener(`resize`,n,{passive:!0});let r=window.setInterval(()=>o(void 0),600);return()=>{cancelAnimationFrame(e),clearTimeout(t),clearInterval(r),window.removeEventListener(`scroll`,n,{capture:!0}),window.removeEventListener(`resize`,n)}},[c]);let u={width:document.documentElement.clientWidth||window.innerWidth,height:window.innerHeight},d=fe(()=>(l.current!==i&&(l.current=i,s.current.clear()),e.flatMap(e=>{if(!e.el.isConnected)return[];let n=e.el.getBoundingClientRect();if(n.width<=0&&n.height<=0)return[];let r=en[e.state];if(n.bottom+r<0||n.top-r>u.height)return[];let i=s.current.get(e.el);return i||(i=t?$t(t,n,r,u):{ring:`#1A1C21`,halo:`#FFFFFF`,luminance:1},s.current.set(e.el,i)),[{spec:e,rect:n,outset:r,color:i}]})),[e,t,a,i,u.width,u.height]);return d.length===0?null:Z(`div`,{class:`overlay`,"aria-hidden":`true`,children:d.map(({spec:e,rect:t,outset:i,color:a})=>{let o=r??{left:0,top:n,right:u.width,bottom:u.height},s=Rt(t,i,o);if(s.width<=0||s.height<=0)return null;let c=s.top-6-tn,l=c>=o.top+2?c:Math.min(s.top+s.height+6,o.bottom-tn-2),d=Math.max(o.left+4,Math.min(s.left,o.right-120));return Z(`div`,{children:[Z(`div`,{class:`ring`,"data-state":e.state,style:{left:`${s.left}px`,top:`${s.top}px`,width:`${s.width}px`,height:`${s.height}px`,"--ring":a.ring,"--halo":a.halo}}),e.label&&Z(`div`,{class:`ring-label`,style:{left:`${d}px`,top:`${l}px`,maxWidth:`${Math.max(120,o.right-d-8)}px`,"--ring":a.ring,"--halo":a.halo},children:e.label})]},e.key)})})}var rn=`taw-lens:prefs`,an={mode:`docked`,shown:`docked`,side:`right`,sheet:null,theme:`system`},on=(e,t,n)=>typeof e==`string`&&t.includes(e)?e:n,sn=e=>typeof e==`number`&&Number.isFinite(e);function cn(e){if(typeof e!=`object`||!e)return{...an};let t=e,n=t.sheet;return{mode:on(t.mode,[`docked`,`sheet`,`hidden`],an.mode),shown:on(t.shown,[`docked`,`sheet`],an.shown),side:on(t.side,[`left`,`right`],an.side),sheet:typeof n==`object`&&n&&sn(n.x)&&sn(n.y)?{x:n.x,y:n.y}:null,theme:on(t.theme,[`system`,`light`,`dark`],an.theme)}}function ln(e=dn()){try{let t=e?.getItem(rn);return cn(t?JSON.parse(t):null)}catch{return{...an}}}function un(e,t=dn()){try{t?.setItem(rn,JSON.stringify(e))}catch{}}function dn(){try{return window.localStorage}catch{return null}}var fn=e=>e===`system`?`light`:e===`light`?`dark`:`system`,pn=e=>e?e.split(`/`).pop()??e:``;function mn(e,t){let n=t.id===0?[]:e.rootsOf(t.id),r=t.kind;return{id:`n:${t.id}`,kind:r,name:Me(t),detail:r===`block`?t.name:r===`page`?pn(t.template):t.template??t.name,children:[],target:{key:`n:${t.id}`,attribution:{kind:`block`,node:t.id},elements:n,at:t.id},position:Math.min(...n.map(t=>e.position(t)),2**53-1)}}function hn(e,t,n,r){let i=Math.min(...r.map(t=>e.position(t)),2**53-1),a=r[0]?e.nodeOf(r[0]):n.node;if(n.kind===`template`)return{id:t,kind:`template`,name:Y(n.text,40),detail:`${n.file}:${n.line}`,children:[],target:{key:t,attribution:{kind:`template`,node:n.node,text:n.text,source:n},elements:r,at:a},position:i};let o={kind:`field`,node:n.node,read:n.read,path:n.read.key,label:n.read.label},s=Oe(n.read);return{id:t,kind:s?`wp`:`field`,name:Ae(n.read),detail:J(s?`WordPress`:`field`,`taw-core`),children:[],target:{key:t,attribution:{kind:`field`,node:n.node,text:``,source:o},elements:r,at:a},position:i}}function gn(e){let t=new Map;for(let n of e.manifest.nodes.values())t.set(n.id,mn(e,n));let n=new Map;for(let t of e.sources().values()){let e=t.kind===`field`?gt(t.read):ht(t);n.has(e)||n.set(e,{node:t.node,source:t})}for(let[r,{node:i,source:a}]of n){let n=t.get(i),o=e.elementsOf(r);n&&o.length>0&&n.children.push(hn(e,r,a,o))}for(let n of e.manifest.nodes.values())n.parent!==null&&t.get(n.parent)?.children.push(t.get(n.id));let r=e=>{for(let t of e.children)e.position=Math.min(e.position,r(t));let t=e=>e.kind===`page`||e.kind===`part`||e.kind===`block`;return e.children.sort((e,n)=>e.position-n.position||Number(t(n))-Number(t(e))),e.position},i=t.get(0);return r(i),i}var _n=(e,t)=>e.name.toLowerCase().includes(t)||e.detail.toLowerCase().includes(t);function vn(e,t,n=``){let r=n.trim().toLowerCase(),i=[],a=new Set;if(r){let t=e=>{let n=_n(e,r);for(let r of e.children)n=t(r)||n;return n&&a.add(e.id),n};t(e)}let o=(e,n,s)=>{if(r&&!a.has(e.id))return;let c=r?!0:t.has(e.id);if(i.push({node:e,depth:n,parent:s,expanded:c&&e.children.length>0}),c)for(let t of e.children)o(t,n+1,e.id)};return o(e,0,null),i}function yn(e,t){let n=[],r=e=>{if(e.id===t)return!0;for(let t of e.children)if(r(t))return n.unshift(e.id),!0;return!1};return r(e)?n:[]}function bn(e){let t=e.attribution;return t.kind===`block`?`n:${t.node}`:t.kind===`field`?gt(t.source.read):t.kind===`template`?ht(t.source):null}var xn=({kind:e})=>Z(e===`page`?$e:e===`part`?et:e===`block`?tt:e===`template`?rt:nt,{});function Sn({root:e,selected:t,reveal:n,onHover:r,onSelect:i,onBack:a}){let[o,s]=W(``),[c,l]=W(()=>new Set([e.id])),[u,d]=W(e.id),f=q(u),p=e=>{f.current=e,d(e)},[m,h]=W(null),g=q(new Map),_=q(!1);K(()=>{let r=[...t,...n?[n]:[]].flatMap(t=>yn(e,t));n&&r.push(n),r.length>0&&l(e=>new Set([...e,...r])),n&&(p(n),_.current=!0)},[e,n,t]);let v=fe(()=>vn(e,c,o),[e,c,o]),y=v[Math.max(0,v.findIndex(e=>e.node.id===u))];de(()=>{_.current&&y&&(g.current.get(y.node.id)?.focus(),g.current.get(y.node.id)?.scrollIntoView?.({block:`nearest`}),_.current=!1)},[y]);let b=(e,t)=>l(n=>{let r=new Set(n);return t??!r.has(e)?r.add(e):r.delete(e),r}),x=e=>{let t=v[Math.max(0,Math.min(v.length-1,e))];t&&(_.current=!0,p(t.node.id),r(t.node.target))};return Z(`div`,{class:`structure`,children:[Z(`div`,{class:`structure-head`,children:[Z(`span`,{class:`structure-title`,id:`lens-structure-title`,children:J(`Structure`,`taw-core`)}),Z(`button`,{type:`button`,class:`text-button`,onClick:a,children:J(`Back to chat`,`taw-core`)})]}),Z(`label`,{class:`sr-only`,for:`lens-tree-filter`,children:J(`Filter`,`taw-core`)}),Z(`input`,{id:`lens-tree-filter`,class:`filter`,type:`search`,autocomplete:`off`,spellcheck:!1,placeholder:J(`Filter blocks, fields, files…`,`taw-core`),value:o,onInput:e=>s(e.currentTarget.value)}),Z(`div`,{class:`tree`,role:`tree`,"aria-labelledby":`lens-structure-title`,onKeyDown:e=>{let t=v.findIndex(e=>e.node.id===f.current),n=Math.max(0,t),r=v[n];if(!r)return;let{node:a,expanded:o,parent:s}=r;switch(e.key){case`ArrowDown`:x(n+1);break;case`ArrowUp`:x(n-1);break;case`Home`:x(0);break;case`End`:x(v.length-1);break;case`ArrowRight`:a.children.length>0&&!o?b(a.id,!0):o&&x(n+1);break;case`ArrowLeft`:o?b(a.id,!1):s&&x(v.findIndex(e=>e.node.id===s));break;case`Enter`:case` `:i(a.target,e.shiftKey);break;default:return}e.preventDefault()},onMouseLeave:()=>{h(null),r(null)},children:[v.map(({node:e,depth:n,expanded:a})=>{let o=t.has(e.id),s=e.target.elements.length>0;return Z(`div`,{ref:t=>{t?g.current.set(e.id,t):g.current.delete(e.id)},class:`tree-row`,role:`treeitem`,"aria-level":n+1,"aria-selected":o,"aria-expanded":e.children.length>0?a:void 0,tabIndex:e.id===y?.node.id?0:-1,"data-selected":o?``:void 0,style:{paddingLeft:`${8+n*16}px`},onMouseEnter:()=>{h(e.id),r(e.target)},onFocus:()=>p(e.id),onClick:t=>{p(e.id),i(e.target,t.shiftKey)},children:[e.children.length>0?Z(`span`,{class:`tree-twisty`,onClick:t=>{t.stopPropagation(),b(e.id)},children:Z(a?Ze:Qe,{})}):Z(`span`,{class:`tree-twisty`}),Z(`span`,{class:`tree-icon`,children:Z(xn,{kind:e.kind})}),Z(`span`,{class:`tree-name`,children:e.name}),Z(`span`,{class:`tree-detail mono`,children:e.detail}),m===e.id&&s&&Z(`span`,{class:`tree-hint`,children:J(`on page`,`taw-core`)})]},e.id)}),v.length===0&&Z(`div`,{class:`tree-none`,children:J(`Nothing matches.`,`taw-core`)})]}),Z(`div`,{class:`structure-help`,children:J(`Hover a row to ring it on the page. Click to select; shift-click to add it to the chat.`,`taw-core`)})]})}var Cn=new WeakMap,wn=0,Tn=e=>{let t=Cn.get(e);return t||(t=`e:${++wn}`,Cn.set(e,t)),t},En=e=>J(e===`light`?`Theme: light. Switch to dark`:e===`dark`?`Theme: dark. Follow the system`:`Theme: follows the system. Switch to light`,`taw-core`),Dn=({theme:e})=>Z(e===`light`?qe:e===`dark`?Je:Ke,{});function On({host:e,initial:t,env:n}){let[r,i]=W(t),[a,o]=W(()=>n.viewport()),[s,c]=W(null),[l,u]=W(null),[d,f]=W(``),p=q(null),m=q(null),h=q(null),g=pe(e=>i(t=>({...t,...e})),[]),_=n.page,[v,y]=W(void 0),b=v??null,[x,S]=W(!1),[C,w]=W(`chat`),[T,E]=W([]),[D,O]=W(null),[k,A]=W(null),[j,ee]=W(null),[M,te]=W(0),N=pe(()=>{let e=_?.map(!0)??null;return y(e),e},[_]);K(()=>_?.onAsset(()=>te(e=>e+1)),[_]);let ne=pe((e,t)=>({key:Tn(t),attribution:e?e.attribute(t):{kind:`element`,node:0,text:``},elements:[t],at:e?e.nodeOf(t):0}),[]),re=pe((e,t)=>{E(n=>t?n.some(t=>t.key===e.key)?n.filter(t=>t.key!==e.key):[...n,e]:[e]),O(e.key),f(`${J(`Selected`,`taw-core`)} ${b?Fe(b.manifest,e):``}`.trim())},[b]),P=()=>{E([]),O(null),f(J(`Selection cleared`,`taw-core`))};K(()=>{if(!x||!_||r.mode===`hidden`)return;if(v===void 0){N();return}let e=b,t=_.pick({hover:t=>A(t?{target:ne(e,t),label:!0}:null),pick:(t,n)=>{let r=ne(e,t);re(r,n);let i=bn(r);i&&ee(i)},stop:()=>S(!1)});return()=>{t(),A(null)}},[x,_,r.mode,v,b,N,ne,re]);let F=fe(()=>b?gn(b):null,[b]),I=fe(()=>new Set(T.map(bn).filter(e=>e!==null)),[T]),L=()=>{if(C===`tree`){w(`chat`);return}N(),w(`tree`)},R=e=>{b||N(),w(`tree`),ee(bn(e))},z=(e,t)=>{re(e,t),e.elements[0]&&_?.reveal(e.elements[0])},B=T.find(e=>e.key===D)??T[T.length-1]??null,ie=r.mode===`hidden`?[]:[...T.flatMap(e=>e.elements.map((t,n)=>({key:`${e.key}:${n}`,el:t,state:`selected`,label:n===0&&b?Pe(b.manifest,e):void 0}))),...k?k.target.elements.filter(e=>!T.some(t=>t.elements.includes(e))).map((e,t)=>({key:`hover:${t}`,el:e,state:`hover`,label:k.label&&t===0&&b?Pe(b.manifest,k.target):void 0})):[]];K(()=>n.push(r.mode===`docked`?r.side:null),[n,r.mode,r.side]),K(()=>n.theme(r.theme),[n,r.theme]),K(()=>n.save(r),[n,r]),K(()=>n.onResize(()=>o(n.viewport())),[n]),K(()=>{h.current===`reopen`?p.current?.focus():h.current===`hide`&&m.current?.focus(),h.current=null},[r.mode]);let V=Pt(r.sheet??Nt(a),a),H=e=>{g({mode:`docked`,shown:`docked`,side:e}),f(J(e===`left`?`Lens docked on the left`:`Lens docked on the right`,`taw-core`))},ae=()=>{g({mode:`sheet`,shown:`sheet`,sheet:V}),f(J(`Lens undocked into a sheet`,`taw-core`))},oe=()=>{h.current=`reopen`,S(!1),g({mode:`hidden`})},se=()=>{h.current=`hide`,g({mode:r.shown})},ce=e=>{r.mode!==`sheet`||e.button!==0||e.target.closest(`button`)||(e.currentTarget.setPointerCapture?.(e.pointerId),c({pointer:e.pointerId,start:{x:e.clientX,y:e.clientY},from:V}),e.preventDefault())},le=e=>{if(!s||e.pointerId!==s.pointer)return;let t=Pt({x:s.from.x+e.clientX-s.start.x,y:s.from.y+e.clientY-s.start.y},a);g({sheet:t}),u(jt(t.x,a))},ue=e=>{s&&e.pointerId===s.pointer&&(c(null),u(null),l&&H(l))};K(()=>{if(!s)return;let e=e=>{e.key===`Escape`&&(g({sheet:s.from}),c(null),u(null))};return document.addEventListener(`keydown`,e,!0),()=>document.removeEventListener(`keydown`,e,!0)},[s,g]);let U=Z(`div`,{class:`sr-only`,role:`status`,"aria-live":`polite`,children:d});if(r.mode===`hidden`)return Z(`div`,{class:`lens`,children:[Z(`button`,{ref:p,type:`button`,class:`reopen`,"data-side":r.side,"aria-label":J(`Show Lens`,`taw-core`),title:J(`Show Lens`,`taw-core`),onClick:se,children:Z(Re,{})}),U]});let G=r.mode===`docked`,de=G?{"--lens-top":`${a.top}px`}:{left:`${V.x}px`,top:`${V.y}px`,height:`${Mt(a)}px`};return Z(`div`,{class:`lens`,children:[l&&Z(`div`,{class:`dock-zone`,"data-side":l,"aria-hidden":`true`,style:{"--lens-top":`${a.top}px`},children:Z(`span`,{class:`dock-zone-label`,children:J(l===`left`?`Release to dock left`:`Release to dock right`,`taw-core`)})}),Z(`aside`,{class:`panel`,"aria-label":J(`TAW Lens`,`taw-core`),"data-mode":r.mode,"data-side":r.side,"data-dragging":s?``:void 0,style:de,children:[Z(`div`,{class:`header`,onPointerDown:ce,onPointerMove:le,onPointerUp:ue,onPointerCancel:ue,children:[Z(`div`,{class:`brand`,children:[G?Z(Re,{}):Z(`span`,{class:`grip`,children:Z(Ye,{})}),Z(`span`,{class:`brand-name`,children:J(`Lens`,`taw-core`)}),G&&e&&Z(`span`,{class:`brand-host`,children:e})]}),Z(`div`,{class:`tools`,children:[Z(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Pick an element`,`taw-core`),title:J(x?`Stop picking (Esc)`:`Pick an element`,`taw-core`),"aria-pressed":x,"aria-disabled":_?void 0:`true`,onClick:()=>_&&S(!x),children:Z(ze,{})}),Z(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Structure tree`,`taw-core`),title:J(`Structure tree`,`taw-core`),"aria-pressed":C===`tree`,"aria-disabled":_?void 0:`true`,onClick:()=>_&&L(),children:Z(Be,{})}),Z(`button`,{type:`button`,class:`icon-button`,"aria-label":En(r.theme),title:En(r.theme),onClick:()=>g({theme:fn(r.theme)}),children:Z(Dn,{theme:r.theme})}),G?Z(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Undock into a sheet`,`taw-core`),title:J(`Undock into a sheet`,`taw-core`),onClick:ae,children:Z(Ve,{})}):Z(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Dock as Inspector`,`taw-core`),title:J(`Dock as Inspector`,`taw-core`),onClick:()=>H(Ft(V,a)),children:Z(He,{})}),Z(`button`,{ref:m,type:`button`,class:`icon-button`,"aria-label":J(`Hide Lens`,`taw-core`),title:J(`Hide Lens`,`taw-core`),onClick:oe,children:G?Z(Ue,{side:r.side}):Z(We,{})})]})]}),Z(`div`,{class:`body`,children:C===`tree`?F?Z(Sn,{root:F,selected:I,reveal:j,onHover:e=>A(e?{target:e,label:!1}:null),onSelect:z,onBack:()=>w(`chat`)}):Z(`div`,{class:`empty`,children:[Z(`div`,{class:`empty-title`,children:J(`No source map on this page`,`taw-core`)}),Z(`div`,{class:`empty-text`,children:J(`Lens maps what TAW renders. Reload the page; if it stays empty, check that the theme calls wp_footer().`,`taw-core`)})]}):B?Z(`div`,{class:`empty`,children:Z(`div`,{class:`empty-text`,children:J(`No conversation yet. Select something on the page, or tell Claude what to change.`,`taw-core`)})}):Z(`div`,{class:`empty`,children:[Z(ze,{size:22}),Z(`div`,{class:`empty-title`,children:J(`Point at anything on the page`,`taw-core`)}),Z(`div`,{class:`empty-text`,children:J(`Click an element to see what TAW knows about it, or open the structure tree. Then tell Claude what to change.`,`taw-core`)}),Z(`div`,{class:`gestures`,children:[Z(`span`,{children:[Z(`span`,{class:`kbd`,children:J(`Click`,`taw-core`)}),` `,J(`select`,`taw-core`)]}),Z(`span`,{children:[Z(`span`,{class:`kbd`,children:J(`⇧ Click`,`taw-core`)}),` `,J(`add to selection`,`taw-core`)]}),Z(`span`,{children:[Z(`span`,{class:`kbd`,children:J(`Esc`,`taw-core`)}),` `,J(`stop picking`,`taw-core`)]})]})]})}),C===`chat`&&B&&b&&_&&Z(At,{manifest:b.manifest,target:B,editor:_.editor,onClose:P,onShowInTree:R}),T.length>0&&(C===`tree`||T.length>1)&&Z(`div`,{class:`chips`,role:`list`,"aria-label":J(`Selection`,`taw-core`),children:[T.map(e=>Z(`span`,{role:`listitem`,children:Z(`button`,{type:`button`,class:`chip mono`,"data-primary":T.length>1&&e.key===B?.key?``:void 0,onMouseEnter:()=>A({target:e,label:!1}),onMouseLeave:()=>A(null),onFocus:()=>A({target:e,label:!1}),onBlur:()=>A(null),onClick:()=>{O(e.key),w(`chat`)},children:b?Fe(b.manifest,e):e.key})},e.key)),T.length===1&&Z(`span`,{class:`chips-hint`,children:J(`Shift-click to add more`,`taw-core`)})]}),Z(`div`,{class:`composer`,children:[Z(`label`,{class:`sr-only`,for:`lens-message`,children:J(`Message`,`taw-core`)}),Z(`div`,{class:`composer-box`,"data-disabled":``,children:[Z(`input`,{id:`lens-message`,class:`composer-input`,disabled:!0,placeholder:J(`Start Lens to chat`,`taw-core`)}),Z(`button`,{type:`button`,class:`send`,"aria-label":J(`Send`,`taw-core`),disabled:!0,children:Z(Ge,{})})]})]})]}),Z(nn,{rings:ie,sampler:_?.sampler??null,top:a.top,area:Lt(a,r.mode===`docked`?r.side:null),refresh:M}),U]})}var kn=`taw-lens-pick`,$=24;function An(e,t){try{let n=e.createElement(`canvas`);n.width=$,n.height=$;let r=n.getContext(`2d`,{willReadFrequently:!0});return r?(r.drawImage(t,0,0,$,$),{data:r.getImageData(0,0,$,$).data}):null}catch{return null}}function jn(e,t,n,r){if(!e||t.width<=0||t.height<=0)return null;let i=Math.min(23,Math.max(0,Math.floor((n-t.left)/t.width*$))),a=(Math.min(23,Math.max(0,Math.floor((r-t.top)/t.height*$)))*$+i)*4,o=e.data;return{r:o[a],g:o[a+1],b:o[a+2],a:o[a+3]/255}}function Mn(e,t,n){let r=e.defaultView??window,i=new WeakMap,a=new Map,o=new Map,s,c=t=>{if(s===void 0&&(s=e.createElement(`canvas`).getContext(`2d`,{willReadFrequently:!0})),!s)return null;s.clearRect(0,0,1,1),s.fillStyle=`#000`,s.fillStyle=t,s.fillRect(0,0,1,1);let[n,r,i,a]=s.getImageData(0,0,1,1).data;return{r:n,g:r,b:i,a:a/255}};return{stackAt:(n,r)=>e.elementsFromPoint(n,r).filter(e=>e!==t&&!t.contains(e)),style:e=>r.getComputedStyle(e),color(e){return o.has(e)||o.set(e,qt(e,c)),o.get(e)??null},media(t,n,r){let a=t,o=t.currentSrc||t.src||`canvas`;if(t.tagName===`IMG`&&!t.complete)return null;let s=i.get(t);return(!s||s.key!==o)&&(s={key:o,pixels:An(e,a)},i.set(t,s)),jn(s.pixels,t.getBoundingClientRect(),n,r)},backgroundImage(t,r,i,o){let s=a.get(r);if(!s){let t=new Image,i={image:t,pixels:null,ready:!1};t.crossOrigin=`anonymous`,t.onload=()=>{i.pixels=An(e,t),i.ready=!0,n()},t.onerror=()=>{i.ready=!0},t.src=new URL(r,e.baseURI).href,a.set(r,i),s=i}return s.ready?jn(s.pixels,t.getBoundingClientRect(),i,o):null}}}var Nn=[`pointerdown`,`pointerup`,`mousedown`,`mouseup`,`click`,`dblclick`,`auxclick`,`touchstart`];function Pn(e,t,n){let r=e.defaultView??window,i=e=>e.composedPath().includes(t),a=n=>{let r=n.target;return!(r instanceof Element)||r===t||t.contains(r)||r===e.documentElement?null:r},o=e=>n.hover(i(e)?null:a(e)),s=e=>{e.relatedTarget||n.hover(null)},c=e=>{if(!i(e)&&(e.preventDefault(),e.stopImmediatePropagation(),e.type===`click`)){let t=a(e);t&&n.pick(t,e.shiftKey)}},l=e=>{e.key===`Escape`&&(e.preventDefault(),e.stopImmediatePropagation(),n.stop())},u=e.getElementById(kn);u||(u=e.createElement(`style`),u.id=kn,u.textContent=`html, html * { cursor: crosshair !important; }`,e.head.appendChild(u)),r.addEventListener(`pointermove`,o,!0),e.addEventListener(`mouseout`,s,!0);for(let e of Nn)r.addEventListener(e,c,{capture:!0,passive:!1});return r.addEventListener(`keydown`,l,!0),()=>{r.removeEventListener(`pointermove`,o,!0),e.removeEventListener(`mouseout`,s,!0);for(let e of Nn)r.removeEventListener(e,c,{capture:!0});r.removeEventListener(`keydown`,l,!0),e.getElementById(kn)?.remove(),n.hover(null)}}function Fn(e,t){let n=Ee(e);return n?new xt(e,n,t):null}var In={surface:`#FFFFFF`,line:`#ECEDF0`,text:`#1A1C21`,muted:`#5F6672`,mutedSoft:`#6B7280`,bubble:`#F4F5F7`,chip:`#F0F1F3`,accent:`#1A1C21`,onAccent:`#FFFFFF`,buttonLine:`#DDE0E5`,composerLine:`#E3E5E9`,amberLine:`#F0DDB6`,amberFill:`#FFFBF3`,amberDot:`#C98512`,amberText:`#6B5A35`,amberRule:`#F3E7CC`,diffRemovedFill:`#FDEEEF`,diffRemovedText:`#9F2430`,diffAddedFill:`#EAF6EF`,diffAddedText:`#1C6B3E`},Ln={surface:`#17191E`,line:`#262A31`,text:`#E7E9ED`,muted:`#9AA1AD`,mutedSoft:`#9AA1AD`,bubble:`#22252C`,chip:`#262A31`,accent:`#E7E9ED`,onAccent:`#17191E`,buttonLine:`#3A3F4B`,composerLine:`#30343C`,amberLine:`#4A3D22`,amberFill:`#1E1A12`,amberDot:`#E0A33E`,amberText:`#C9B68E`,amberRule:`#3A3122`,diffRemovedFill:`#3A1F24`,diffRemovedText:`#F4B9BF`,diffAddedFill:`#173023`,diffAddedText:`#A6E0BE`},Rn={dockedWidth:`400px`,sheetWidth:`400px`,sheetHeight:`600px`,sheetRadius:`12px`,sheetShadow:`0 1px 2px rgba(20,24,33,.05), 0 12px 32px rgba(20,24,33,.08)`,fontUi:`Inter, system-ui, -apple-system, "Segoe UI", sans-serif`,fontMono:`"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace`},zn=e=>`--lens-${e.replace(/[A-Z]/g,e=>`-${e.toLowerCase()}`)}`,Bn=e=>Object.entries(e).map(([e,t])=>`${zn(e)}: ${t};`).join(` `);function Vn(){return[`:host { ${Bn(In)} ${Bn(Rn)} color-scheme: light; }`,`@media (prefers-color-scheme: dark) { :host(:not([data-theme="light"])) { ${Bn(Ln)} color-scheme: dark; } }`,`:host([data-theme="dark"]) { ${Bn(Ln)} color-scheme: dark; }`].join(`
`)}var Hn=String.raw,Un=()=>Vn()+Hn`
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

        @media (prefers-reduced-motion: no-preference) {
            .icon-button,
            .send,
            .reopen {
                transition:
                    background-color 0.12s ease,
                    color 0.12s ease;
            }
        }
    `,Wn=`taw-lens`,Gn=`taw-lens-push`,Kn=`vscode://file/{path}:{line}`;function qn(e,t,n=Kn){let r=e.defaultView??window,i=new Set,a;return{map(n=!1){return(n||a===void 0)&&(a=Fn(e,t)),a},pick:n=>Pn(e,t,n),sampler:Mn(e,t,()=>i.forEach(e=>e())),onAsset(e){return i.add(e),()=>i.delete(e)},reveal(e){let t=e.getBoundingClientRect();(t.bottom<0||t.top>r.innerHeight)&&e.scrollIntoView({block:`center`,behavior:`smooth`})},editor:n}}function Jn(e,t){let n=e.defaultView??window;return{viewport(){let t=e.getElementById(`wpadminbar`),r=t&&n.getComputedStyle(t).position===`fixed`?Math.max(0,t.getBoundingClientRect().bottom):0;return{width:e.documentElement.clientWidth||n.innerWidth,height:n.innerHeight,top:r}},push(t){let n=e.getElementById(Gn);if(t===null){n?.remove();return}n||(n=e.createElement(`style`),n.id=Gn,e.head.appendChild(n)),n.textContent=It(t)},theme(e){e===`system`?t.removeAttribute(`data-theme`):t.setAttribute(`data-theme`,e)},save:e=>un(e),onResize(e){return n.addEventListener(`resize`,e),()=>n.removeEventListener(`resize`,e)},page:qn(e,t,window.tawLens?.editor||Kn)}}function Yn(e=document){let t=e.querySelector(Wn);if(t)return t;let n=e.createElement(Wn);e.documentElement.appendChild(n);let r=n.attachShadow({mode:`open`}),i=e.createElement(`style`);i.textContent=Un(),r.appendChild(i);let a=e.createElement(`div`);r.appendChild(a);let o=e.defaultView?.location.host??``;return P(Z(On,{host:window.tawLens?.host||o,initial:ln(),env:Jn(e,n)}),a),n}typeof document<`u`&&(document.readyState===`loading`?document.addEventListener(`DOMContentLoaded`,()=>Yn(),{once:!0}):Yn());
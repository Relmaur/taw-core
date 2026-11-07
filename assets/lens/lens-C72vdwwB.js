var e,t,n,r,i,a,o,s,c,l,u={},d=[],f=/^m(i|n|o|s|text|space)$/,p=Array.isArray,m=d.slice,h=Object.assign;function g(e){e&&e.parentNode&&e.remove()}function _(n,r,i,a,o){var s={type:n,props:r,key:i,ref:a,__k:null,__:null,__b:0,__e:null,__c:null,constructor:void 0,__v:o||++t,__i:-1,__u:0};return!o&&e.vnode&&e.vnode(s),s}function v(e){return e.children}function y(e,t){this.props=e,this.context=t,this.__g=0}function b(e,t){if(t==null)return e.__?b(e.__,e.__i+1):null;for(var n;t<e.__k.length;t++)if((n=e.__k[t])&&n.__e)return n.__e;return typeof e.type!=`function`||e.props.__P?null:b(e)}function x(e){if((e=e.__)&&e.__c&&!e.props.__P)return e.__e=null,e.__k.some(function(t){return t&&(e.__e=t.__e)}),x(e)}function S(t){(8&t.__g||!(t.__g|=8)||!n.push(t)||i++)&&r==e.debounceRendering||((r=e.debounceRendering)||queueMicrotask)(C)}function C(){var t,r,o,s,c,l,u,d,f;try{for(r=1;n.length;)n.length>r&&n.sort(a),t=n.shift(),r=n.length,8&t.__g&&(s=void 0,c=void 0,l=(c=(o=t).__v).__e,u=[],d=[],(f=o.__P)&&((s=h({constructor:void 0},c)).__v=c.__v+1,e.vnode&&e.vnode(s),j(f,s,c,o.__n,f.namespaceURI,32&c.__u?[l]:null,u,l||b(c),32&c.__u,d),s.__v=c.__v,s.__.__k[s.__i]=s,M(u,s,d),c.__=c.__e=null,s.__e!=l&&x(s)))}finally{n.length=i=0}}function w(e,t,n,r,i,a,o,s,c,l,f){var p,m,h,g,_,v,y=r.__k||d,b=t.length;for(c=T(n,t,y,c,b),p=0;p<b;p++)(h=n.__k[p])!=null&&(m=~h.__i&&y[h.__i]||u,h.__i=p,v=j(e,h,m,i,a,o,s,c,l,f),g=h.__e,(m.ref!=h.ref||8&m.__u)&&(m.ref&&N(m.ref,null,h,m),h.ref&&f.push(h.ref,h.__c||g,h)),_||=g,4&h.__u?(c=E(h,c,e,!m.__v),m.__e&&(m.__e=null)):typeof h.type==`function`&&v!==void 0?c=v:g&&(c=g.nextSibling),h.__u&=-7);return n.__e=_,c}function T(e,t,n,r,i){var a,o,s,c,l,u,d,f,m,h,g=n.length,y=g,x=0,S=!1,C=e.__k=Array(i);for(a=0;a<i;a++)(o=t[a])!=null&&typeof o!=`boolean`&&typeof o!=`function`?(typeof o!=`object`||o.constructor==String?o=C[a]=_(null,o):p(o)?o=C[a]=_(v,{children:o}):o.constructor===void 0&&o.__b?o=C[a]=_(o.type,o.props,o.key,o.ref,o.__v):C[a]=o,c=a+x,o.__=e,o.__b=e.__b+1,s=null,~(l=o.__i=D(o,n,c,y))&&(y--,(s=n[l])&&(s.__u|=2)),s&&s.__v?(o.__u|=2,l==c-1?x--:l==c+1?x++:l!=c&&(l>c?x--:x++,S=!0)):(~l||(i>g?x--:i<g&&x++),typeof o.type!=`function`&&(o.__u|=4))):C[a]=null;if(S){for(u=[],d=[],a=0;a<i;a++)if((o=C[a])&&2&o.__u){for(f=0,m=u.length;f<m;)u[h=f+m>>1]<o.__i?f=h+1:m=h;u[f]=o.__i,d[a]=f+1}for(x=u.length;a--;)d[a]&&(d[a]==x?x--:C[a].__u|=4)}if(y)for(a=0;a<g;a++)!(s=n[a])||2&s.__u||(s.__e==r&&(r=b(s)),P(s,s));return r}function E(e,t,n,r){var i,a,o;if(typeof e.type==`function`){if(e.props.__P)return t;if(i=e.__k)for(a=0;a<i.length;a++)i[a]&&(i[a].__=e,t=E(i[a],t,n,!1));return t}for(t&&!t.parentNode&&(t=b(e))&&!t.parentNode&&(t=null),o=t;o&&o.nodeType==8;)o=o.nextSibling;for(e.__e!=o&&(!r&&n.moveBefore&&e.__e.parentNode?n.moveBefore(e.__e,t):n.insertBefore(e.__e,t||null)),t=e.__e;(t&&=t.nextSibling)&&t.nodeType==8;);return t}function D(e,t,n,r){var i,a,o,s=e.key,c=e.type,l=t[n],u=l&&!(2&l.__u);if(l===null&&s==null||u&&s==l.key&&c==l.type)return n;if(r>+!!u){for(i=n-1,a=n+1;i>=0||a<t.length;)if((l=t[o=i>=0?i--:a++])&&!(2&l.__u)&&s==l.key&&c==l.type)return o}return-1}function O(e,t,n){n??=``,t[0]==`-`?e.setProperty(t,n):e[t]=n}function k(e,t,n,r,i){var a,o;n:if(t==`style`){if(typeof n==`string`)e.style.cssText=n;else{if(typeof r==`string`&&(e.style.cssText=r=``),r)for(t in r)n&&t in n||O(e.style,t,``);if(n)for(t in n)r&&n[t]==r[t]||O(e.style,t,n[t])}}else if(t[0]==`o`&&t[1]==`n`)(e.__e||={})[t]=n,n&&r||(o=l[t]||(l[t]=A(t)),(e.__a||={})[t]=c,a=t!=(t=t.replace(s,`$1`)),(t=t.slice(2))[0]<`a`&&(t=t.toLowerCase()),n?e.addEventListener(t,o,a):e.removeEventListener(t,o,a));else{if(i==`http://www.w3.org/2000/svg`)t=t.replace(/xlink(H|:h)/,`h`).replace(/sName$/,`s`);else if(t!=`width`&&t!=`height`&&t!=`href`&&t!=`list`&&t!=`form`&&t!=`tabIndex`&&t!=`download`&&t!=`rowSpan`&&t!=`colSpan`&&t!=`role`&&t!=`popover`&&t in e)try{e[t]=n??``;break n}catch{}typeof n==`function`||(n==null||!1===n&&t[4]!=`-`?e.removeAttribute(t):e.setAttribute(t,t==`popover`&&n==1?``:n))}}function A(t){return function(n){if(this.__e){var r=this.__e[t];if(n[o]==null)n[o]=c++;else if(n[o]<this.__a[t])return;return r(e.event?e.event(n):n)}}}function j(t,n,r,i,a,o,s,c,l,u){var f,m,_,x,S,C,T,E,D,O,k,A,j,M,N,F,I,L,R,z,B=n.type;if(n.constructor!==void 0)return null;if(128&r.__u&&(l=32&r.__u,f=r.__c.__z)){if(n.__u|=l,m=o=[],f.nodeType==8)for(_=1,x=f.nextSibling;x;x=x.nextSibling){if(x.nodeType==8){if(x.data.startsWith(`$s`))_++;else if(x.data.startsWith(`/$s`)&&!--_)break}o.push(x)}else o.push(f);c=o[0]}(f=e.__b)&&f(n);n:if(typeof B==`function`){S=s.length;try{if(O=n.props,k=(f=B.prototype)&&f.render,A=(f=B.contextType)&&i[f.__c],j=f?A?A.props.value:f.__:i,r.__c?2&(C=n.__c=r.__c).__g&&(C.__g|=1):(k?n.__c=C=new B(O,j):(n.__c=C=new y(O,j),C.constructor=B,C.render=ne),A&&A.sub(C),C.state||(C.state={}),C.__n=i,C.__g|=8,C.__h=[],C.__k=[]),k&&(C.__s||(C.__s=C.state),B.getDerivedStateFromProps&&(C.__s==C.state&&(C.__s=h({},C.__s)),h(C.__s,B.getDerivedStateFromProps(O,C.__s)))),T=C.props,E=C.state,C.__v=n,r.__c){if(k&&!B.getDerivedStateFromProps&&O!==T&&C.componentWillReceiveProps&&C.componentWillReceiveProps(O,j),n.__v==r.__v&&!(8&C.__g)||!(4&C.__g)&&C.shouldComponentUpdate&&!1===C.shouldComponentUpdate(O,C.__s,j)){n.__v!=r.__v&&(C.props=O,C.state=C.__s,C.__g&=-9),n.__e=r.__e,n.__k=r.__k,n.__k.some(function(e){e&&(e.__=n)}),d.push.apply(C.__h,C.__k),C.__k=[],C.__h.length&&s.push(C),c=b(r);break n}C.componentWillUpdate&&C.componentWillUpdate(O,C.__s,j),k&&C.componentDidUpdate&&C.__h.push(function(){C.componentDidUpdate(T,E,D)})}else k&&!B.getDerivedStateFromProps&&C.componentWillMount&&C.componentWillMount(),k&&C.componentDidMount&&C.__h.push(C.componentDidMount);if(C.context=j,C.props=O,C.__P=t,C.__g&=-5,M=e.__r,N=0,k)C.state=C.__s,C.__g&=-9,M&&M(n),f=C.render(C.props,C.state,C.context),d.push.apply(C.__h,C.__k),C.__k=[];else do C.__g&=-9,M&&M(n),f=C.render(C.props,C.state,C.context),C.state=C.__s;while(8&C.__g&&++N<25);C.state=C.__s,C.getChildContext&&(i=h({},i,C.getChildContext())),k&&r.__c&&C.getSnapshotBeforeUpdate&&(D=C.getSnapshotBeforeUpdate(T,E)),F=f&&f.type===v&&f.key==null?f.props.children:f,O.__P&&(f=c,a=(t=O.__P).namespaceURI,l=o=null,r.props&&r.props.__P!=t&&(r.__k.some(function(e){e&&P(e,e)}),r.__k=null),c=r.__k?b(r,0):null),c=w(t,p(F)?F:[F],n,r,i,a,o,s,c,l,u),O.__P&&(n.__e=null,c=f),n.__u&=-161,128&r.__u&&(C.__z=null),m&&m.some(g),C.__h.length&&s.push(C),1&C.__g&&(C.__g&=-4)}catch(t){if(s.length=S,n.__v=null,l||o){if(t.then){if(I=0,n.__u|=l?160:128,o){for(~(R=o.indexOf(c||void 0))||(R=o.length);(z=o[R-1])&&z.nodeType==8;)R--;for(;R<o.length;R++)if(z=o[R]){if(o[R]=null,z.nodeType==8){if(z.data.startsWith(`$s`))I++||(L=z);else if(I&&z.data.startsWith(`/$s`)&&!--I){c=z;break}}else if(!I)break}}if(!L){for(;c&&c.nodeType==8&&c.nextSibling;)c=c.nextSibling;L=c}n.__c.__z||(n.__c.__z=L),n.__e=c}else o&&o.some(g)}else n.__e=r.__e;n.__k||=r.__k||[],t.then||ee(n),e.__e(t,n,r)}}else c=n.__e=te(r.__e,n,r,i,a,o,s,l,u,t);return(f=e.diffed)&&f(n),128&n.__u?void 0:c}function ee(e){e&&(e.__c&&(e.__c.__g|=4),e.__k&&e.__k.some(ee))}function M(t,n,r){for(var i=0;i<r.length;)N(r[i++],r[i++],r[i++]);e.__c&&e.__c(n,t),t.some(function(n){try{t=n.__h,n.__h=[],t.some(function(e){e.call(n)})}catch(t){e.__e(t,n.__v)}})}function te(t,n,r,i,a,o,s,c,l,d){var h,_,v,y,x,S,C,T,E,D=r.props||u,O=n.props,A=n.type;if(A==`svg`?a=`http://www.w3.org/2000/svg`:A==`math`?a=`http://www.w3.org/1998/Math/MathML`:a||=`http://www.w3.org/1999/xhtml`,o){for(h=0;h<o.length;h++)if((x=o[h])&&(A?x.localName==A:x.nodeType==3)){t=x,o[h]=null;break}}if(!t){if(T=d.ownerDocument||document,!A)return T.createTextNode(O);t=T.createElementNS(a,A,O.is&&O),c&&=(e.__m&&e.__m(n,o),!1),o=null}if(A){if(d=A==`template`?t.content:t,o=A==`textarea`&&O.defaultValue!=null?null:o&&m.call(d.childNodes),!c&&o)for(D={},h=0;h<t.attributes.length;h++)D[(x=t.attributes[h]).name]=x.value;for(h in D)x=D[h],h==`dangerouslySetInnerHTML`?v=x:h==`children`||h in O||h==`value`&&`defaultValue`in O||h==`checked`&&`defaultChecked`in O||k(t,h,null,x,a);for(h in E=1&r.__u,O)x=O[h],h==`children`?y=x:h==`dangerouslySetInnerHTML`?_=x:h==`value`?S=x:h==`checked`?C=x:c&&typeof x!=`function`||!(D[h]!==x||E&&x!=null)||k(t,h,x,D[h],a);_?(c||v&&(_.__html==v.__html||_.__html==t.innerHTML)||(t.innerHTML=_.__html),n.__k=[]):(v&&(t.textContent=``),(A==`foreignObject`||a==`http://www.w3.org/1998/Math/MathML`&&f.test(A))&&(a=`http://www.w3.org/1999/xhtml`),w(d,p(y)?y:[y],n,r,i,a,o,s,o?o[0]:r.__k&&b(r,0),c,l),o&&o.some(g)),c&&A!=`textarea`||(h=`value`,A==`progress`&&S==null?t.removeAttribute(h):S==null||S===t[h]&&(A!=`progress`||S)||k(t,h,S,D[h],a),h=`checked`,C!=null&&C!=t[h]&&k(t,h,C,D[h],a))}else D===O||c&&t.data==O||(t.data=O);return t}function N(t,n,r,i){try{typeof t==`function`?n?n.__x=t(n)||1:(i&&=i.__c||i.__e)&&(n=i.__x)&&(i.__x=null,typeof n==`function`?n():t(null)):t.current=n}catch(t){e.__e(t,r)}}function P(t,n,r){var i,a;if(e.unmount&&e.unmount(t),!(i=t.ref)||i.current&&i.current!=t.__e||N(i,null,n,t),i=t.__c){if(i.componentWillUnmount)try{i.componentWillUnmount()}catch(t){e.__e(t,n)}i.__P=i.__n=null}if(i=t.__k)for(a=0;a<i.length;a++)i[a]&&P(i[a],n,typeof t.type!=`function`||r&&!t.props.__P);(i=t.__e)&&(r||g(i),i.__e&&(i.__e=null)),t.__e=t.__c=t.__=null}function ne(e,t,n){return this.constructor(e,n)}function F(t,n){var r,i,a,o;e.__&&e.__(t,n),n.nodeType==9&&(n=n.documentElement),i=(r=t&&32&t.__u)?null:n.__k,n.__k=_(v,{children:[t]}),a=[],o=[],j(n,n.__k,i||u,u,n.namespaceURI,i?null:n.firstChild?m.call(n.childNodes):null,a,i?i.__e:n.firstChild,r,o),M(a,n.__k,o),n.__k.props.children=null}e={__e:function(e,t,n,r){for(var a,o,s;t=t.__;)if((a=t.__c)&&!(1&a.__g)){a.__g|=4;try{if((o=a.constructor)&&o.getDerivedStateFromError&&(a.setState(o.getDerivedStateFromError(e)),s=8&a.__g),a.componentDidCatch&&(a.componentDidCatch(e,r||{}),s=8&a.__g),s)return void(a.__g|=2)}catch(t){e=t,s=0}}throw i=0,e}},t=0,y.prototype.setState=function(e,t){var n=this.__s;n&&n!=this.state||(n=this.__s=h({},this.state)),typeof e==`function`&&(e=e(h({},n),this.props)),e&&(h(n,e),this.__v&&(t&&this.__k.push(t),S(this)))},y.prototype.forceUpdate=function(e){this.__v&&(this.__g|=4,e&&this.__h.push(e),S(this))},y.prototype.render=v,n=[],i=0,a=function(e,t){return e.__v.__b-t.__v.__b},o=Symbol(),s=/(PointerCapture)$|Capture$/i,c=0,l={};var I,L,R,z,B=Object.is,V=0,re=[],H=[],U=e,ie=U.__b,ae=U.__r,oe=U.diffed,se=U.__c,ce=U.unmount,le=U.__;function W(e,t){U.__h&&U.__h(L,e,V||t),V=0;var n=L.__H||(L.__H={__:[],__h:[]});return e>=n.__.length&&n.__.push({}),n.__[e]}function G(e){return V=1,ue(be,e)}function ue(e,t,n){var r=W(I++,2);if(r.t=e,!r.__c&&(r.__=[n?n(t):be(void 0,t),function(e){var t=r.__N?r.__N[0]:r.__[0],n=r.t(t,e);B(t,n)||(r.__N=[n,r.__[1]],r.__c.setState({}))}],r.__c=L,!L.__f)){L.__f=!0;var i=L.shouldComponentUpdate;L.shouldComponentUpdate=function(e,t,n){var r=this.__H;if(!r)return!0;var a=!1,o=this.props!=e;if(r.__.some(function(e){e.__N&&(a=!0,B(e.__[0],e.__N[0])||(o=!0))}),i){var s=i.call(this,e,t,n);return a?s||o:s}return!a||o}}return r.__}function K(e,t){var n=W(I++,3);!U.__s&&ye(n.__H,t)&&(n.__P=!0,n.__=e,n.u=t,L.__H.__h.push(n))}function de(e){return V=5,fe(function(){return{current:e}},[])}function fe(e,t){var n=W(I++,7);return ye(n.__H,t)&&(n.__=e(),n.__H=t),n.__}function pe(e,t){return V=8,fe(function(){return e},t)}function me(){var e;do{for(;e=H.shift();)try{q(e)}catch(t){U.__e(t,{__:(e=e.__P)&&e.__v})}for(;e=re.shift();){var t=e.__H;if(e.__P&&t)try{t.__h.some(q),t.__h.some(ve),t.__h=[]}catch(n){t.__h=[],U.__e(n,e.__v)}}}while(H.length)}U.__b=function(e){L=null,ie&&ie(e)},U.__=function(e,t){e&&t.__k&&t.__k.__m&&(e.__m=t.__k.__m),le&&le(e,t)},U.__r=function(e){ae&&ae(e),I=0;var t=(L=e.__c).__H;t&&(R==L?L.__h=[]:(t.__h.some(q),t.__h.some(ve),I=0),t.__h=[],t.__.some(function(e){e.__N&&(e.__=e.__N),e.u=e.__N=void 0})),R=L},U.diffed=function(e){oe&&oe(e);var t=e.__c;t&&t.__H&&(t.__H.__h.length&&_e(re.push(t)),t.__H.__.some(function(e){e.u&&(e.__H=e.u)})),R=L=null},U.__c=function(e,t){t.some(function(e){try{e.__h.some(q),e.__h=e.__h.filter(function(e){return!e.__||ve(e)})}catch(n){t.some(function(e){e.__h&&=[]}),t=[],U.__e(n,e.__v)}}),se&&se(e,t)},U.unmount=function(e){ce&&ce(e);var t,n,r=e.__c;r&&r.__H&&(r.__H.__.some(function(r){try{if(r.__P&&r.__c){if(n===void 0){for(n=e.__;n&&(!n.__c||!n.__c.__P);)n=n.__;n&&=n.__c}r.__P=n,_e(H.push(r))}else q(r)}catch(e){t=e}}),r.__H=void 0,t&&U.__e(t,r.__v))};var he=typeof requestAnimationFrame==`function`;function ge(e){var t,n=function(){clearTimeout(r),he&&cancelAnimationFrame(t),setTimeout(e)},r=setTimeout(n,35);he&&(t=requestAnimationFrame(n))}function _e(e){e!=1&&z==U.requestAnimationFrame||((z=U.requestAnimationFrame)||ge)(me)}function q(e){var t=L,n=e.__c;typeof n==`function`&&(e.__c=void 0,n()),L=t}function ve(e){var t=L;e.__c=e.__(),L=t}function ye(e,t){return!e||e.length!=t.length||t.some(function(t,n){return!B(t,e[n])})}function be(e,t){return typeof t==`function`?t(e):t}var xe=()=>window.wp?.i18n,J=(e,t)=>xe()?.__(e,t)??e,Se=0;Array.isArray;function Y(t,n,r,i,a,o){n||={};var s,c,l=n;if(`ref`in l&&typeof t!=`function`)for(c in l={},n)c==`ref`?s=n[c]:l[c]=n[c];var u={type:t,props:l,key:r,ref:s,__k:null,__:null,__b:0,__e:null,__c:null,constructor:void 0,__v:--Se,__i:-1,__u:0};return(a||o)&&(u.__source=a,u.__self=o),e.vnode&&e.vnode(u),u}var X=({size:e=16,width:t=1.8,children:n})=>Y(`svg`,{width:e,height:e,viewBox:`0 0 24 24`,fill:`none`,stroke:`currentColor`,"stroke-width":t,"stroke-linecap":`round`,"stroke-linejoin":`round`,"aria-hidden":`true`,focusable:`false`,children:n}),Ce=e=>Y(X,{...e,children:[Y(`circle`,{cx:`11`,cy:`11`,r:`6.5`}),Y(`path`,{d:`M16 16l4.5 4.5`})]}),we=e=>Y(X,{...e,children:Y(`path`,{d:`M5 3l14 7-6 2-2 6z`})}),Te=e=>Y(X,{...e,children:Y(`path`,{d:`M4 5h6M8 12h6M8 19h6M6 5v14M6 12h2M6 19h2`})}),Ee=e=>Y(X,{...e,children:[Y(`rect`,{x:`4`,y:`6`,width:`12`,height:`12`,rx:`2`}),Y(`path`,{d:`M13 3h8v8M21 3l-8 8`})]}),De=e=>Y(X,{...e,children:[Y(`rect`,{x:`3`,y:`4`,width:`18`,height:`16`,rx:`2`}),Y(`path`,{d:`M15 4v16`})]}),Oe=({side:e,...t})=>Y(X,{...t,children:Y(`path`,{d:e===`right`?`M9 6l6 6-6 6`:`M15 6l-6 6 6 6`})}),ke=e=>Y(X,{...e,children:Y(`path`,{d:`M6 12h12`})}),Ae=e=>Y(X,{...e,width:2,children:Y(`path`,{d:`M12 19V5M6 11l6-6 6 6`})}),je=e=>Y(X,{...e,children:[Y(`circle`,{cx:`12`,cy:`12`,r:`8`}),Y(`path`,{d:`M12 4a8 8 0 0 1 0 16z`,fill:`currentColor`})]}),Me=e=>Y(X,{...e,children:[Y(`circle`,{cx:`12`,cy:`12`,r:`4`}),Y(`path`,{d:`M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4`})]}),Ne=e=>Y(X,{...e,children:Y(`path`,{d:`M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z`})}),Pe=({size:e=14})=>Y(`svg`,{width:e,height:e,viewBox:`0 0 24 24`,fill:`currentColor`,"aria-hidden":`true`,focusable:`false`,children:[Y(`circle`,{cx:`9`,cy:`6`,r:`1.6`}),Y(`circle`,{cx:`15`,cy:`6`,r:`1.6`}),Y(`circle`,{cx:`9`,cy:`12`,r:`1.6`}),Y(`circle`,{cx:`15`,cy:`12`,r:`1.6`}),Y(`circle`,{cx:`9`,cy:`18`,r:`1.6`}),Y(`circle`,{cx:`15`,cy:`18`,r:`1.6`})]});function Fe(e,t,n=400){return e<120?`left`:e+n>t.width-120?`right`:null}var Ie=e=>Math.max(240,Math.min(600,e.height-e.top-24));function Le(e){return Re({x:e.width-400-24,y:e.top+72},e)}function Re(e,t,n=400){let r=Math.max(12,t.width-n-12),i=t.top+12,a=Math.max(i,t.height-Ie(t)-12);return{x:Math.round(Math.min(r,Math.max(12,e.x))),y:Math.round(Math.min(a,Math.max(i,e.y)))}}var ze=(e,t,n=400)=>e.x+n/2<t.width/2?`left`:`right`;function Be(e,t=400){return`html { margin-${e}: ${t}px !important; }`}var Ve=`taw-lens:prefs`,Z={mode:`docked`,shown:`docked`,side:`right`,sheet:null,theme:`system`},Q=(e,t,n)=>typeof e==`string`&&t.includes(e)?e:n,He=e=>typeof e==`number`&&Number.isFinite(e);function Ue(e){if(typeof e!=`object`||!e)return{...Z};let t=e,n=t.sheet;return{mode:Q(t.mode,[`docked`,`sheet`,`hidden`],Z.mode),shown:Q(t.shown,[`docked`,`sheet`],Z.shown),side:Q(t.side,[`left`,`right`],Z.side),sheet:typeof n==`object`&&n&&He(n.x)&&He(n.y)?{x:n.x,y:n.y}:null,theme:Q(t.theme,[`system`,`light`,`dark`],Z.theme)}}function We(e=Ke()){try{let t=e?.getItem(Ve);return Ue(t?JSON.parse(t):null)}catch{return{...Z}}}function Ge(e,t=Ke()){try{t?.setItem(Ve,JSON.stringify(e))}catch{}}function Ke(){try{return window.localStorage}catch{return null}}var qe=e=>e===`system`?`light`:e===`light`?`dark`:`system`,Je=e=>J(e===`light`?`Theme: light. Switch to dark`:e===`dark`?`Theme: dark. Follow the system`:`Theme: follows the system. Switch to light`,`taw-core`),Ye=({theme:e})=>Y(e===`light`?Me:e===`dark`?Ne:je,{});function Xe({host:e,initial:t,env:n}){let[r,i]=G(t),[a,o]=G(()=>n.viewport()),[s,c]=G(null),[l,u]=G(null),[d,f]=G(``),p=de(null),m=de(null),h=de(null),g=pe(e=>i(t=>({...t,...e})),[]);K(()=>n.push(r.mode===`docked`?r.side:null),[n,r.mode,r.side]),K(()=>n.theme(r.theme),[n,r.theme]),K(()=>n.save(r),[n,r]),K(()=>n.onResize(()=>o(n.viewport())),[n]),K(()=>{h.current===`reopen`?p.current?.focus():h.current===`hide`&&m.current?.focus(),h.current=null},[r.mode]);let _=Re(r.sheet??Le(a),a),v=e=>{g({mode:`docked`,shown:`docked`,side:e}),f(J(e===`left`?`Lens docked on the left`:`Lens docked on the right`,`taw-core`))},y=()=>{g({mode:`sheet`,shown:`sheet`,sheet:_}),f(J(`Lens undocked into a sheet`,`taw-core`))},b=()=>{h.current=`reopen`,g({mode:`hidden`})},x=()=>{h.current=`hide`,g({mode:r.shown})},S=e=>{r.mode!==`sheet`||e.button!==0||e.target.closest(`button`)||(e.currentTarget.setPointerCapture?.(e.pointerId),c({pointer:e.pointerId,start:{x:e.clientX,y:e.clientY},from:_}),e.preventDefault())},C=e=>{if(!s||e.pointerId!==s.pointer)return;let t=Re({x:s.from.x+e.clientX-s.start.x,y:s.from.y+e.clientY-s.start.y},a);g({sheet:t}),u(Fe(t.x,a))},w=e=>{s&&e.pointerId===s.pointer&&(c(null),u(null),l&&v(l))};K(()=>{if(!s)return;let e=e=>{e.key===`Escape`&&(g({sheet:s.from}),c(null),u(null))};return document.addEventListener(`keydown`,e,!0),()=>document.removeEventListener(`keydown`,e,!0)},[s,g]);let T=Y(`div`,{class:`sr-only`,role:`status`,"aria-live":`polite`,children:d});if(r.mode===`hidden`)return Y(`div`,{class:`lens`,children:[Y(`button`,{ref:p,type:`button`,class:`reopen`,"data-side":r.side,"aria-label":J(`Show Lens`,`taw-core`),title:J(`Show Lens`,`taw-core`),onClick:x,children:Y(Ce,{})}),T]});let E=r.mode===`docked`,D=E?{"--lens-top":`${a.top}px`}:{left:`${_.x}px`,top:`${_.y}px`,height:`${Ie(a)}px`};return Y(`div`,{class:`lens`,children:[l&&Y(`div`,{class:`dock-zone`,"data-side":l,"aria-hidden":`true`,style:{"--lens-top":`${a.top}px`},children:Y(`span`,{class:`dock-zone-label`,children:J(l===`left`?`Release to dock left`:`Release to dock right`,`taw-core`)})}),Y(`aside`,{class:`panel`,"aria-label":J(`TAW Lens`,`taw-core`),"data-mode":r.mode,"data-side":r.side,"data-dragging":s?``:void 0,style:D,children:[Y(`div`,{class:`header`,onPointerDown:S,onPointerMove:C,onPointerUp:w,onPointerCancel:w,children:[Y(`div`,{class:`brand`,children:[E?Y(Ce,{}):Y(`span`,{class:`grip`,children:Y(Pe,{})}),Y(`span`,{class:`brand-name`,children:J(`Lens`,`taw-core`)}),E&&e&&Y(`span`,{class:`brand-host`,children:e})]}),Y(`div`,{class:`tools`,children:[Y(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Pick an element`,`taw-core`),"aria-pressed":`false`,"aria-disabled":`true`,children:Y(we,{})}),Y(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Structure tree`,`taw-core`),"aria-pressed":`false`,"aria-disabled":`true`,children:Y(Te,{})}),Y(`button`,{type:`button`,class:`icon-button`,"aria-label":Je(r.theme),title:Je(r.theme),onClick:()=>g({theme:qe(r.theme)}),children:Y(Ye,{theme:r.theme})}),E?Y(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Undock into a sheet`,`taw-core`),title:J(`Undock into a sheet`,`taw-core`),onClick:y,children:Y(Ee,{})}):Y(`button`,{type:`button`,class:`icon-button`,"aria-label":J(`Dock as Inspector`,`taw-core`),title:J(`Dock as Inspector`,`taw-core`),onClick:()=>v(ze(_,a)),children:Y(De,{})}),Y(`button`,{ref:m,type:`button`,class:`icon-button`,"aria-label":J(`Hide Lens`,`taw-core`),title:J(`Hide Lens`,`taw-core`),onClick:b,children:E?Y(Oe,{side:r.side}):Y(ke,{})})]})]}),Y(`div`,{class:`body`,children:Y(`div`,{class:`empty`,children:[Y(we,{size:22}),Y(`div`,{class:`empty-title`,children:J(`Point at anything on the page`,`taw-core`)}),Y(`div`,{class:`empty-text`,children:J(`Click an element to see what TAW knows about it, or open the structure tree. Then tell Claude what to change.`,`taw-core`)}),Y(`div`,{class:`gestures`,children:[Y(`span`,{children:[Y(`span`,{class:`kbd`,children:J(`Click`,`taw-core`)}),` `,J(`select`,`taw-core`)]}),Y(`span`,{children:[Y(`span`,{class:`kbd`,children:J(`⇧ Click`,`taw-core`)}),` `,J(`add to selection`,`taw-core`)]}),Y(`span`,{children:[Y(`span`,{class:`kbd`,children:J(`Esc`,`taw-core`)}),` `,J(`stop picking`,`taw-core`)]})]})]})}),Y(`div`,{class:`composer`,children:[Y(`label`,{class:`sr-only`,for:`lens-message`,children:J(`Message`,`taw-core`)}),Y(`div`,{class:`composer-box`,"data-disabled":``,children:[Y(`input`,{id:`lens-message`,class:`composer-input`,disabled:!0,placeholder:J(`Start Lens to chat`,`taw-core`)}),Y(`button`,{type:`button`,class:`send`,"aria-label":J(`Send`,`taw-core`),disabled:!0,children:Y(Ae,{})})]})]})]}),T]})}var Ze={surface:`#FFFFFF`,line:`#ECEDF0`,text:`#1A1C21`,muted:`#5F6672`,mutedSoft:`#6B7280`,bubble:`#F4F5F7`,chip:`#F0F1F3`,accent:`#1A1C21`,onAccent:`#FFFFFF`,buttonLine:`#DDE0E5`,composerLine:`#E3E5E9`,amberLine:`#F0DDB6`,amberFill:`#FFFBF3`,amberDot:`#C98512`,amberText:`#6B5A35`,amberRule:`#F3E7CC`,diffRemovedFill:`#FDEEEF`,diffRemovedText:`#9F2430`,diffAddedFill:`#EAF6EF`,diffAddedText:`#1C6B3E`},Qe={surface:`#17191E`,line:`#262A31`,text:`#E7E9ED`,muted:`#9AA1AD`,mutedSoft:`#9AA1AD`,bubble:`#22252C`,chip:`#262A31`,accent:`#E7E9ED`,onAccent:`#17191E`,buttonLine:`#3A3F4B`,composerLine:`#30343C`,amberLine:`#4A3D22`,amberFill:`#1E1A12`,amberDot:`#E0A33E`,amberText:`#C9B68E`,amberRule:`#3A3122`,diffRemovedFill:`#3A1F24`,diffRemovedText:`#F4B9BF`,diffAddedFill:`#173023`,diffAddedText:`#A6E0BE`},$e={dockedWidth:`400px`,sheetWidth:`400px`,sheetHeight:`600px`,sheetRadius:`12px`,sheetShadow:`0 1px 2px rgba(20,24,33,.05), 0 12px 32px rgba(20,24,33,.08)`,fontUi:`Inter, system-ui, -apple-system, "Segoe UI", sans-serif`,fontMono:`"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace`},et=e=>`--lens-${e.replace(/[A-Z]/g,e=>`-${e.toLowerCase()}`)}`,$=e=>Object.entries(e).map(([e,t])=>`${et(e)}: ${t};`).join(` `);function tt(){return[`:host { ${$(Ze)} ${$($e)} color-scheme: light; }`,`@media (prefers-color-scheme: dark) { :host(:not([data-theme="light"])) { ${$(Qe)} color-scheme: dark; } }`,`:host([data-theme="dark"]) { ${$(Qe)} color-scheme: dark; }`].join(`
`)}var nt=String.raw,rt=()=>tt()+nt`
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

        @media (prefers-reduced-motion: no-preference) {
            .icon-button,
            .send,
            .reopen {
                transition:
                    background-color 0.12s ease,
                    color 0.12s ease;
            }
        }
    `,it=`taw-lens`,at=`taw-lens-push`;function ot(e,t){let n=e.defaultView??window;return{viewport(){let t=e.getElementById(`wpadminbar`),r=t&&n.getComputedStyle(t).position===`fixed`?Math.max(0,t.getBoundingClientRect().bottom):0;return{width:e.documentElement.clientWidth||n.innerWidth,height:n.innerHeight,top:r}},push(t){let n=e.getElementById(at);if(t===null){n?.remove();return}n||(n=e.createElement(`style`),n.id=at,e.head.appendChild(n)),n.textContent=Be(t)},theme(e){e===`system`?t.removeAttribute(`data-theme`):t.setAttribute(`data-theme`,e)},save:e=>Ge(e),onResize(e){return n.addEventListener(`resize`,e),()=>n.removeEventListener(`resize`,e)}}}function st(e=document){let t=e.querySelector(it);if(t)return t;let n=e.createElement(it);e.documentElement.appendChild(n);let r=n.attachShadow({mode:`open`}),i=e.createElement(`style`);i.textContent=rt(),r.appendChild(i);let a=e.createElement(`div`);r.appendChild(a);let o=e.defaultView?.location.host??``;return F(Y(Xe,{host:window.tawLens?.host||o,initial:We(),env:ot(e,n)}),a),n}typeof document<`u`&&(document.readyState===`loading`?document.addEventListener(`DOMContentLoaded`,()=>st(),{once:!0}):st());
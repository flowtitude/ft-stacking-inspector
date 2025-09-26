<?php
/**
 * Plugin Name: FT Stacking Inspector (MVP)
 * Description: Panel flotante para inspeccionar y ajustar en vivo orden de apilamiento (stacking) y z-index / order. Toggle: Alt/Option + Z.
 * Version:     0.1.3
 * Author:      Flowtitude
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_enqueue_scripts', function () {
	// Solo usuarios logueados con permiso de edición, en el FRONT.
	if ( ! is_user_logged_in() || ! current_user_can('edit_posts') || is_admin() ) return;

	$handle = 'ft-stacking-inspector';
	wp_register_script($handle, '', [], false, true);

	$js = <<<'JS'
(() => {
	/* ===========================
	   FT STACKING INSPECTOR (MVP)
	   Toggle: Alt/Option + Z (fix Mac: usar e.code === 'KeyZ')
	=========================== */

	const STATE = {
		enabled: false,
		tree: null,
		currentTab: 'stack',
		highlights: new Set(),
		mods: new Map(),
		cache: new Map(), // Cache para getComputedStyle
		throttleTimer: null,
	};

	const STYLE = `
	.ftsi-root{position:fixed;inset:auto 16px 16px auto;z-index:2147483646;font:13px/1.4 system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111}
	.ftsi-card{width:440px;max-height:70vh;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.12);overflow:hidden}
	.ftsi-head{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid #f1f5f9;background:#f8fafc}
	.ftsi-title{font-weight:600}
	.ftsi-actions{display:flex;gap:8px}
	.ftsi-btn{appearance:none;border:1px solid #e5e7eb;background:#fff;border-radius:8px;padding:6px 8px;cursor:pointer}
	.ftsi-btn[aria-pressed="true"]{background:#111;color:#fff;border-color:#111}
	.ftsi-body{display:flex;flex-direction:column;gap:8px;padding:10px;max-height:60vh;overflow:auto}
	.ftsi-row{display:flex;gap:8px;align-items:center}
	.ftsi-search{flex:1;padding:6px 8px;border:1px solid #e5e7eb;border-radius:8px}
	.ftsi-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:6px}
	.ftsi-item{border:1px solid #e5e7eb;border-radius:8px;padding:8px;background:#fff}
	.ftsi-item .ftsi-top{display:flex;flex-direction:column;gap:4px}
	.ftsi-selector{font-weight:600;word-break:break-all;line-height:1.2}
	.ftsi-classes{font-size:11px;color:#64748b;word-break:break-all;line-height:1.2}
	.ftsi-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px}
	.ftsi-badge{border:1px solid #e5e7eb;border-radius:999px;padding:2px 6px;font-size:11px;background:#f8fafc}
	.ftsi-ctrls{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
	.ftsi-ctrls input{width:80px;padding:4px 6px;border:1px solid #e5e7eb;border-radius:6px}
	.ftsi-ctrls .ftsi-btn{padding:4px 6px}
	.ftsi-muted{color:#64748b}
	.ftsi-small{font-size:11px}
	.ftsi-highlight{position:absolute;pointer-events:none;border:2px solid #14b8a6;box-shadow:0 0 0 2px rgba(20,184,166,.25) inset;z-index:2147483645}
	.ftsi-footer{display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border-top:1px solid #f1f5f9;background:#fafafa}
	.ftsi-link{color:#0369a1;text-decoration:none}

	/* Botón lanzador fijo */
	.ftsi-launch{position:fixed;right:16px;bottom:16px;z-index:2147483646}
	.ftsi-launch .ftsi-btn{font-weight:600}
	`;

	let $root, $card, $list, $search, $tabStack, $tabDom, $count, $resetBtn, $launch;

	function ensureUI(){
		if($root) return;

		// Estilos
		const style = document.createElement('style');
		style.textContent = STYLE;
		document.documentElement.appendChild(style);

		// Panel
		$root = document.createElement('div');
		$root.className = 'ftsi-root';
		$root.style.display = 'none';
		$root.innerHTML = `
			<div class="ftsi-card" role="dialog" aria-label="FT Stacking Inspector" aria-modal="false">
				<div class="ftsi-head">
					<div class="ftsi-title">Flowtitude Stacking Inspector</div>
					<div class="ftsi-actions">
						<button class="ftsi-btn" data-tab="stack" aria-pressed="true" title="Orden de pintura / stacking">Stack</button>
						<button class="ftsi-btn" data-tab="dom" aria-pressed="false" title="Orden del DOM">DOM</button>
						<button class="ftsi-btn" data-action="close" title="Ocultar (Alt/Option+Z)">✕</button>
					</div>
				</div>
				<div class="ftsi-body">
					<div class="ftsi-row">
						<input class="ftsi-search" type="search" placeholder="Filtrar por selector, id, clase…">
						<span class="ftsi-small ftsi-muted"><span data-count>0</span> nodos</span>
					</div>
					<ul class="ftsi-list" data-list></ul>
				</div>
				<div class="ftsi-footer">
					<a class="ftsi-link" href="https://developer.mozilla.org/docs/Web/CSS/CSS_Positioning/Understanding_z-index/Stacking_context" target="_blank" rel="noreferrer">MDN: Stacking contexts</a>
					<button class="ftsi-btn" data-action="reset-all">Reset all</button>
				</div>
			</div>
		`;
		document.body.appendChild($root);

		// Lanzador fijo por si el atajo falla
		$launch = document.createElement('div');
		$launch.className = 'ftsi-launch';
		$launch.innerHTML = `<button class="ftsi-btn" title="Abrir FT Stacking (Alt/Option+Z)">Flowtitude Stacking</button>`;
		document.body.appendChild($launch);

		$card = $root.querySelector('.ftsi-card');
		$list = $root.querySelector('[data-list]');
		$search = $root.querySelector('.ftsi-search');
		$tabStack = $root.querySelector('[data-tab="stack"]');
		$tabDom = $root.querySelector('[data-tab="dom"]');
		$count = $root.querySelector('[data-count]');
		$resetBtn = $root.querySelector('[data-action="reset-all"]');

		$root.addEventListener('click', (e)=>{
			const btn = e.target.closest('button');
			if(!btn) return;
			if(btn.dataset.tab){
				STATE.currentTab = btn.dataset.tab;
				$tabStack.setAttribute('aria-pressed', STATE.currentTab === 'stack' ? 'true':'false');
				$tabDom.setAttribute('aria-pressed', STATE.currentTab === 'dom' ? 'true':'false');
				render();
			}
			if(btn.dataset.action === 'close'){
				toggle(false);
			}
			if(btn.dataset.action === 'reset-all'){
				resetAll();
			}
		});
		$search.addEventListener('input', ()=>render());
		$launch.addEventListener('click', ()=>toggle(true));
	}

	function toggle(on){
		STATE.enabled = (on ?? !STATE.enabled);
		ensureUI();
		if(STATE.enabled){
			$root.style.display = '';
			build();
		}else{
			$root.style.display = 'none';
			clearHighlights();
		}
	}

	// Cache para getComputedStyle (optimización de rendimiento)
	function getCachedStyle(el, prop = null){
		if(!STATE.cache.has(el)){
			STATE.cache.set(el, getComputedStyle(el));
		}
		const cs = STATE.cache.get(el);
		return prop ? cs[prop] : cs;
	}

	// Limpiar cache cuando sea necesario
	function clearCache(){
		STATE.cache.clear();
	}

	// Atajo: Alt/Option + Z (fix Mac layouts que devuelven Ω con Option)
	window.addEventListener('keydown', (e)=>{
		// Usamos e.code para garantizar la tecla física Z.
		if (e.altKey && (e.code === 'KeyZ' || (typeof e.key === 'string' && e.key.toLowerCase() === 'z'))) {
			e.preventDefault();
			// Throttling para evitar múltiples activaciones
			if(STATE.throttleTimer) return;
			STATE.throttleTimer = setTimeout(() => {
				STATE.throttleTimer = null;
				toggle();
			}, 100);
		}
	},{capture:true});

	// API global mínima por si quieres abrir desde consola
	window.ftsiOpen = ()=>toggle(true);
	window.ftsiClose = ()=>toggle(false);

	// ====== Lógica de análisis optimizada ======
	function build(){
		// Limpiar cache previo
		clearCache();
		
		const fn = ()=> {
			try {
				STATE.tree = analyze();
				render();
			} catch(e) {
				console.warn('[FT] Error en análisis:', e);
			}
		};
		
		// Usar requestIdleCallback con timeout más largo para páginas complejas
		if('requestIdleCallback' in window){
			requestIdleCallback(fn, { timeout: 1000 });
		}else{
			setTimeout(fn, 50);
		}
	}

	function isElementVisible(el){
		const cs = getCachedStyle(el);
		if(cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return false;
		const rect = el.getBoundingClientRect();
		return rect.width > 0 && rect.height > 0;
	}

	function shortSelector(el){
		const tag = el.tagName.toLowerCase();
		const id = el.id ? `#${el.id}`:'';
		const cls = (el.className && typeof el.className === 'string')
			? '.' + el.className.trim().split(/\s+/).slice(0,3).join('.')
			: '';
		return `${tag}${id}${cls}`;
	}

	function getSelectorParts(el){
		const tag = el.tagName.toLowerCase();
		const id = el.id ? `#${el.id}`:'';
		const allClasses = (el.className && typeof el.className === 'string')
			? el.className.trim().split(/\s+/)
			: [];
		const mainClasses = allClasses.slice(0,3);
		const extraClasses = allClasses.slice(3);
		
		return {
			main: `${tag}${id}${mainClasses.length ? '.' + mainClasses.join('.') : ''}`,
			extra: extraClasses.length ? extraClasses.join(' ') : ''
		};
	}

	function createsStackingContext(el, cs){
		if(el === document.documentElement) return true;
		if(cs.position !== 'static' && cs.zIndex !== 'auto') return true;
		if(parseFloat(cs.opacity) < 1) return true;
		if(cs.transform !== 'none') return true;
		if(cs.filter !== 'none') return true;
		if(cs.perspective !== 'none') return true;
		if(cs.isolation === 'isolate') return true;
		if(cs.willChange && /\b(transform|opacity|filter|perspective)\b/.test(cs.willChange)) return true;
		if(cs.mixBlendMode && cs.mixBlendMode !== 'normal') return true;
		return false;
	}

	function getZIndex(el, cs){
		const zi = cs.zIndex;
		if(zi === 'auto') return 'auto';
		const n = Number(zi);
		return Number.isFinite(n) ? n : 'auto';
	}

	function isFlexItem(el){
		const p = el.parentElement; if(!p) return false;
		const cs = getCachedStyle(p);
		return cs.display.includes('flex');
	}
	function isGridItem(el){
		const p = el.parentElement; if(!p) return false;
		const cs = getCachedStyle(p);
		return cs.display.includes('grid');
	}
	function isFlexContainer(el){
		const cs = getCachedStyle(el);
		return cs.display.includes('flex');
	}
	function isGridContainer(el){
		const cs = getCachedStyle(el);
		return cs.display.includes('grid');
	}

	function analyze(){
		const all = Array.from(document.body.querySelectorAll('*')).filter(isElementVisible);
		const ctxRoot = { el: document.documentElement, children: [], nodes: [], parent: null, label: 'root<html>' };
		const contextMap = new Map([[document.documentElement, ctxRoot]]);

		function contextOf(el){
			let p = el.parentElement;
			while(p){
				if(contextMap.has(p)) return contextMap.get(p);
				const cs = getCachedStyle(p);
				if(createsStackingContext(p, cs)){
					if(!contextMap.has(p)){
						const node = { el: p, children: [], nodes: [], parent: null, label: shortSelector(p) };
						contextMap.set(p, node);
						const gp = contextOf(p);
						node.parent = gp;
						gp.children.push(node);
					}
					return contextMap.get(p);
				}
				p = p.parentElement;
			}
			return ctxRoot;
		}

		for(const el of all){
			const cs = getCachedStyle(el);
			if(createsStackingContext(el, cs) && !contextMap.has(el)){
				const node = { el, children: [], nodes: [], parent: null, label: shortSelector(el) };
				contextMap.set(el, node);
				const parentCtx = contextOf(el);
				node.parent = parentCtx;
				parentCtx.children.push(node);
			}
		}
		for(const el of all){
			const ctx = contextOf(el);
			const cs = getCachedStyle(el);
			ctx.nodes.push({
				el,
				selector: shortSelector(el),
				zIndex: getZIndex(el, cs),
				position: cs.position,
				opacity: parseFloat(cs.opacity),
				createsCtx: createsStackingContext(el, cs),
				isFlexItem: isFlexItem(el),
				isGridItem: isGridItem(el),
			});
		}

		function sortByPaint(list){
			const neg = [], auto = [], pos = [];
			for(const n of list){
				if(n.zIndex === 'auto') auto.push(n);
				else if(n.zIndex < 0) neg.push(n);
				else pos.push(n);
			}
			neg.sort((a,b)=>a.zIndex-b.zIndex);
			pos.sort((a,b)=>a.zIndex-b.zIndex);
			return [...neg, ...auto, ...pos];
		}
		function annotateDOMOrder(nodes){
			nodes.forEach((n,i)=> n.domIndex = i);
		}
		const domNodes = all.map((el,i)=>{
			const cs = getCachedStyle(el);
			return {
				el, selector: shortSelector(el),
				zIndex: getZIndex(el, cs),
				position: cs.position,
				opacity: parseFloat(cs.opacity),
				createsCtx: createsStackingContext(el, cs),
				isFlexItem: isFlexItem(el),
				isGridItem: isGridItem(el),
				domIndex: i
			};
		});

		function walk(ctx){
			annotateDOMOrder(ctx.nodes);
			ctx.nodes = sortByPaint(ctx.nodes);
			ctx.children.forEach(walk);
		}
		walk(ctxRoot);

		return { root: ctxRoot, domNodes };
	}

	function render(){
		if(!$list || !STATE.tree) return;
		$list.innerHTML = '';
		let items = [];

		if(STATE.currentTab === 'dom'){
			items = STATE.tree.domNodes.slice();
		}else{
			const out = [];
			(function walk(ctx, depth=0){
				out.push({ctx, depth, isCtx:true});
				ctx.nodes.forEach(n=> out.push({ ...n, depth, isCtx:false }));
				ctx.children.forEach(c=> walk(c, depth+1));
			})(STATE.tree.root);
			items = out;
		}

		const q = ($search?.value || '').trim().toLowerCase();
		if(q){
			items = items.filter(it=>{
				if(it.isCtx) return it.ctx.label.toLowerCase().includes(q);
				return it.selector.toLowerCase().includes(q);
			});
		}

		const count = items.filter(i=>!i.isCtx).length;
		const $count = document.querySelector('[data-count]');
		if($count) $count.textContent = String(count);

		for(const it of items){
			if(it.isCtx){
				const li = document.createElement('li');
				li.className = 'ftsi-item';
				li.innerHTML = `
					<div class="ftsi-top">
						<strong>CTX</strong>
						<span class="ftsi-muted">${'— '.repeat(it.depth)}${it.ctx.label}</span>
						<span class="ftsi-badges">
							<span class="ftsi-badge">stacking context</span>
						</span>
					</div>
				`;
				$list.appendChild(li);
				continue;
			}
			const li = document.createElement('li');
			li.className = 'ftsi-item';
			const bad = [];
			if(it.createsCtx) bad.push('ctx');
			if(it.position && it.position!=='static') bad.push(it.position);
			if(it.isFlexItem) bad.push('flex-item');
			if(it.isGridItem) bad.push('grid-item');
			if(isFlexContainer(it.el)) bad.push('flex-container');
			if(isGridContainer(it.el)) bad.push('grid-container');

			const selectorParts = getSelectorParts(it.el);

			li.innerHTML = `
				<div class="ftsi-top">
					<div class="ftsi-selector">${selectorParts.main}</div>
					${selectorParts.extra ? `<div class="ftsi-classes">${selectorParts.extra}</div>` : ''}
					<span class="ftsi-badges">
						<span class="ftsi-badge">z:${it.zIndex}</span>
						${bad.map(b=>`<span class="ftsi-badge">${b}</span>`).join('')}
						<span class="ftsi-badge ftsi-muted">DOM#${it.domIndex ?? '–'}</span>
					</span>
				</div>
				<div class="ftsi-ctrls">
					<input type="number" step="1" placeholder="z-index" value="${Number.isFinite(it.zIndex)? it.zIndex : ''}" data-act="z" />
					<button class="ftsi-btn" data-act="z-1">z–</button>
					<button class="ftsi-btn" data-act="z+1">z+</button>
					<input type="number" step="1" placeholder="order" value="${getCachedStyle(it.el, 'order') || ''}" data-act="order" />
					<button class="ftsi-btn" data-act="o-1">o–</button>
					<button class="ftsi-btn" data-act="o+1">o+</button>
					<button class="ftsi-btn" data-act="highlight">Destacar</button>
					<button class="ftsi-btn" data-act="reset">Reset</button>
				</div>
				<div class="ftsi-small ftsi-muted">opacity:${it.opacity}</div>
			`;
			li.addEventListener('click', (e)=>{
				const btn = e.target.closest('button, input');
				if(!btn) return;
				const act = btn.dataset.act;
				if(act === 'highlight'){ highlight(it.el); return; }
				if(act === 'reset'){ resetOne(it.el); render(); return; }
				if(act === 'z'){
					const v = Number(btn.value);
					if(Number.isFinite(v)){ setZ(it.el, v); render(); }
					return;
				}
				if(act === 'order'){
					const v = Number(btn.value);
					if(Number.isFinite(v)){ setOrder(it.el, v); render(); }
					return;
				}
				if(act === 'z-1'){ bumpZ(it.el, -1); render(); return; }
				if(act === 'z+1'){ bumpZ(it.el, +1); render(); return; }
				if(act === 'o-1'){ bumpOrder(it.el, -1); render(); return; }
				if(act === 'o+1'){ bumpOrder(it.el, +1); render(); return; }
			});
			$list.appendChild(li);
		}
	}

	function setZ(el, v){
		const prev = el.style.zIndex;
		el.style.setProperty('z-index', String(v), 'important');
		STATE.mods.set(el, { ...(STATE.mods.get(el)||{}), zIndex: { prev, now: v } });
	}
	function bumpZ(el, delta){
		const cs = getCachedStyle(el);
		const curr = cs.zIndex === 'auto' ? 0 : Number(cs.zIndex)||0;
		setZ(el, curr + delta);
	}
	function setOrder(el, v){
		const prev = el.style.order;
		el.style.setProperty('order', String(v), 'important');
		STATE.mods.set(el, { ...(STATE.mods.get(el)||{}), order: { prev, now: v } });
	}
	function bumpOrder(el, delta){
		const cs = getCachedStyle(el);
		const curr = Number(cs.order)||0;
		setOrder(el, curr + delta);
	}

	let overlay;
	function highlight(el){
		if(!overlay){
			overlay = document.createElement('div');
			overlay.className = 'ftsi-highlight';
			document.body.appendChild(overlay);
		}
		const r = el.getBoundingClientRect();
		Object.assign(overlay.style, {
			left: (window.scrollX + r.left) + 'px',
			top: (window.scrollY + r.top) + 'px',
			width: r.width + 'px',
			height: r.height + 'px',
			display: 'block'
		});
		setTimeout(()=> { if(overlay) overlay.style.display='none'; }, 1200);
	}

	function clearHighlights(){
		if(overlay) overlay.style.display = 'none';
	}

	function resetOne(el){
		const mod = STATE.mods.get(el);
		if(!mod) return;
		if(mod.zIndex) el.style.zIndex = mod.zIndex.prev || '';
		if(mod.order) el.style.order = mod.order.prev || '';
		STATE.mods.delete(el);
	}

	function resetAll(){
		for(const el of STATE.mods.keys()) resetOne(el);
		STATE.mods.clear();
		clearHighlights();
		clearCache();
		build();
	}

	console.info('[FT] Stacking Inspector listo. Pulsa Alt/Option+Z o el botón “FT Stacking”.');
})();
JS;

	wp_add_inline_script($handle, $js);
	wp_enqueue_script($handle);
});

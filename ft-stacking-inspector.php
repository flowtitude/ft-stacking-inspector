<?php
/**
 * Plugin Name: FT Stacking Inspector (MVP)
 * Description: Panel flotante para inspeccionar y ajustar en vivo orden de apilamiento (stacking) y z-index / order. Toggle: Alt/Option + Z.
 * Version:     0.1.8
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
		filteredRoot: null, // Nodo raíz del filtro actual
		filteredPath: [], // Ruta del breadcrumb
		expandedSections: new Set(), // Sections expandidas
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
	.ftsi-breadcrumb{display:flex;align-items:center;gap:4px;font-size:11px;color:#64748b;margin-bottom:8px;flex-wrap:wrap}
	.ftsi-breadcrumb-item{cursor:pointer;color:#0369a1;text-decoration:underline}
	.ftsi-breadcrumb-item:hover{color:#1d4ed8}
	.ftsi-breadcrumb-separator{color:#9ca3af}
	.ftsi-filter-info{font-size:11px;color:#64748b;margin-bottom:8px}
	.ftsi-paint-order{background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:600;margin-left:4px}
	.ftsi-paint-order.high{background:#fef3c7;color:#92400e;border-color:#fde68a}
	.ftsi-paint-order.low{background:#f3e8ff;color:#7c3aed;border-color:#ddd6fe}
	.ftsi-section-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:6px 0;overflow:hidden}
	.ftsi-section-header{display:flex;justify-content:space-between;align-items:center;cursor:pointer;padding:12px;background:#ffffff;border-bottom:1px solid #f1f5f9}
	.ftsi-section-title{font-weight:600;color:#1e40af;display:flex;align-items:center;gap:8px}
	.ftsi-section-count{font-size:11px;color:#64748b;background:#e2e8f0;padding:3px 8px;border-radius:12px;font-weight:500}
	.ftsi-section-toggle{background:#f1f5f9;border:1px solid #d1d5db;color:#64748b;cursor:pointer;font-size:12px;padding:4px 8px;border-radius:4px;font-weight:600}
	.ftsi-section-content{display:none;padding:8px 12px;background:#fafafa;border-top:1px solid #f1f5f9}
	.ftsi-section-content.expanded{display:block}
	.ftsi-section-children{margin-top:8px;padding-left:16px;border-left:3px solid #d1d5db}
	.ftsi-section-info{display:flex;flex-direction:column;gap:4px;margin-top:8px}
	.ftsi-section-badges{display:flex;gap:6px;flex-wrap:wrap}
	.ftsi-section-details{font-size:11px;color:#64748b;line-height:1.4}
	`;

	let $root, $card, $list, $search, $tabStack, $tabDom, $count, $resetBtn, $breadcrumb, $filterInfo, $showAllBtn;

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
					<div class="ftsi-breadcrumb" data-breadcrumb style="display:none"></div>
					<div class="ftsi-filter-info" data-filter-info style="display:none"></div>
					<div class="ftsi-row">
						<input class="ftsi-search" type="search" placeholder="Filtrar por selector, id, clase…">
						<span class="ftsi-small ftsi-muted"><span data-count>0</span> nodos</span>
					</div>
					<ul class="ftsi-list" data-list></ul>
				</div>
				<div class="ftsi-footer">
					<a class="ftsi-link" href="https://developer.mozilla.org/docs/Web/CSS/CSS_Positioning/Understanding_z-index/Stacking_context" target="_blank" rel="noreferrer">MDN: Stacking contexts</a>
					<div>
						<button class="ftsi-btn" data-action="show-all" style="display:none">Ver todo</button>
						<button class="ftsi-btn" data-action="reset-all">Reset all</button>
					</div>
				</div>
			</div>
		`;
		document.body.appendChild($root);

		$card = $root.querySelector('.ftsi-card');
		$list = $root.querySelector('[data-list]');
		$search = $root.querySelector('.ftsi-search');
		$tabStack = $root.querySelector('[data-tab="stack"]');
		$tabDom = $root.querySelector('[data-tab="dom"]');
		$count = $root.querySelector('[data-count]');
		$resetBtn = $root.querySelector('[data-action="reset-all"]');
		$breadcrumb = $root.querySelector('[data-breadcrumb]');
		$filterInfo = $root.querySelector('[data-filter-info]');
		$showAllBtn = $root.querySelector('[data-action="show-all"]');

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
			if(btn.dataset.action === 'show-all'){
				showAll();
			}
		});
		$search.addEventListener('input', ()=>render());
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

		// Calcular orden de pintura global
		const paintOrder = calculatePaintOrder(ctxRoot);

		return { root: ctxRoot, domNodes, paintOrder };
	}

	function render(){
		if(!$list || !STATE.tree) return;
		$list.innerHTML = '';

		// Si no hay filtro activo, mostrar sections colapsadas
		if(!STATE.filteredRoot && STATE.currentTab === 'stack'){
			renderSectionsView();
			return;
		}

		let items = [];

		// Mostrar/ocultar breadcrumb y filtro
		if(STATE.filteredRoot){
			$breadcrumb.style.display = 'flex';
			$filterInfo.style.display = 'block';
			$showAllBtn.style.display = 'inline-block';
			
			// Construir breadcrumb
			$breadcrumb.innerHTML = STATE.filteredPath.map((item, i) => 
				`<span class="ftsi-breadcrumb-item" data-path-index="${i}">${item.label}</span>`
			).join('<span class="ftsi-breadcrumb-separator"> › </span>');
			
			// Event listeners para breadcrumb
			$breadcrumb.addEventListener('click', (e) => {
				const item = e.target.closest('.ftsi-breadcrumb-item');
				if(item){
					const index = parseInt(item.dataset.pathIndex);
					const targetEl = STATE.filteredPath[index].el;
					focusOnElement(targetEl);
				}
			});
		} else {
			$breadcrumb.style.display = 'none';
			$filterInfo.style.display = 'none';
			$showAllBtn.style.display = 'none';
		}

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

		// Filtrar por nodo raíz si está activo
		if(STATE.filteredRoot){
			items = items.filter(item => {
				if(item.isCtx) return true; // Siempre mostrar contextos
				return item.el === STATE.filteredRoot || isDescendantOf(item.el, STATE.filteredRoot);
			});
		}

		const q = ($search?.value || '').trim().toLowerCase();
		if(q){
			items = items.filter(it=>{
				if(it.isCtx) return it.ctx.label.toLowerCase().includes(q);
				return it.selector.toLowerCase().includes(q);
			});
		}

		const count = items.filter(i=>!i.isCtx).length;
		const totalCount = STATE.tree.domNodes.length;
		const $count = document.querySelector('[data-count]');
		if($count) {
			if(STATE.filteredRoot){
				$count.textContent = `${count} de ${totalCount}`;
			} else {
				$count.textContent = String(count);
			}
		}

		// Actualizar información del filtro
		if(STATE.filteredRoot && $filterInfo){
			const rootLabel = shortSelector(STATE.filteredRoot);
			$filterInfo.textContent = `Mostrando descendientes de: ${rootLabel}`;
		}

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
			const paintInfo = STATE.tree.paintOrder.get(it.el);
			const paintOrderClass = paintInfo ? (paintInfo.index < 10 ? 'low' : paintInfo.index > 50 ? 'high' : '') : '';

			li.innerHTML = `
				<div class="ftsi-top">
					<div class="ftsi-selector">
						${selectorParts.main}
						${paintInfo ? `<span class="ftsi-paint-order ${paintOrderClass}" title="Orden de pintura: ${paintInfo.index}">P#${paintInfo.index}</span>` : ''}
					</div>
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
					<button class="ftsi-btn" data-act="focus">Enfocar</button>
					<button class="ftsi-btn" data-act="reset">Reset</button>
				</div>
				<div class="ftsi-small ftsi-muted">
					opacity:${it.opacity}
					${paintInfo ? ` • pintado: ${paintInfo.index}°` : ''}
					${paintInfo ? ` • contexto: ${paintInfo.context}` : ''}
				</div>
			`;
			li.addEventListener('click', (e)=>{
				const btn = e.target.closest('button, input');
				if(!btn) return;
				const act = btn.dataset.act;
				if(act === 'highlight'){ highlight(it.el); return; }
				if(act === 'focus'){ focusOnElement(it.el); return; }
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
		// Recalcular orden de pintura después de cambiar z-index
		setTimeout(() => {
			STATE.tree.paintOrder = calculatePaintOrder(STATE.tree.root);
			render();
		}, 10);
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

	// Funciones de filtrado por nodo
	function focusOnElement(el){
		STATE.filteredRoot = el;
		buildFilteredPath(el);
		render();
	}

	function showAll(){
		STATE.filteredRoot = null;
		STATE.filteredPath = [];
		render();
	}

	function buildFilteredPath(el){
		const path = [];
		let current = el;
		while(current && current !== document.documentElement){
			path.unshift({
				el: current,
				label: shortSelector(current)
			});
			current = current.parentElement;
		}
		STATE.filteredPath = path;
	}

	function isDescendantOf(el, ancestor){
		let current = el.parentElement;
		while(current){
			if(current === ancestor) return true;
			current = current.parentElement;
		}
		return false;
	}

	// Encontrar el elemento raíz principal (main#brx-content)
	function findMainRoot(){
		const mainRoot = document.querySelector('main#brx-content');
		return mainRoot || document.body;
	}

	// Filtrar elementos innecesarios
	function shouldShowElement(el){
		// Excluir elementos del sistema
		if(el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || el.tagName === 'META' || el.tagName === 'LINK') return false;
		if(el.classList.contains('ftsi-root') || el.classList.contains('ftsi-card')) return false;
		
		// Excluir elementos muy pequeños o sin contenido visual
		const rect = el.getBoundingClientRect();
		if(rect.width < 1 && rect.height < 1) return false;
		
		return true;
	}

	// Obtener sections directas del main
	function getMainSections(){
		const mainRoot = findMainRoot();
		const sections = Array.from(mainRoot.querySelectorAll(':scope > section'));
		return sections.filter(shouldShowElement);
	}

	// Calcular orden de pintura global
	function calculatePaintOrder(ctxRoot){
		const paintOrder = new Map();
		let paintIndex = 0;

		function walkContexts(ctx, depth = 0){
			// Pintar nodos de este contexto en orden
			ctx.nodes.forEach(node => {
				paintOrder.set(node.el, {
					index: paintIndex++,
					depth: depth,
					context: ctx.label,
					zIndex: node.zIndex
				});
			});

			// Pintar contextos hijos recursivamente
			ctx.children.forEach(child => {
				walkContexts(child, depth + 1);
			});
		}

		walkContexts(ctxRoot);
		return paintOrder;
	}

	// Renderizar vista de sections colapsadas
	function renderSectionsView(){
		const sections = getMainSections();
		const mainRoot = findMainRoot();
		
		// Mostrar información del main root
		const mainInfo = document.createElement('li');
		mainInfo.className = 'ftsi-item';
		const mainSelector = shortSelector(mainRoot);
		const mainPaintInfo = STATE.tree.paintOrder.get(mainRoot);
		
		mainInfo.innerHTML = `
			<div class="ftsi-top">
				<div class="ftsi-selector">
					${mainSelector}
					${mainPaintInfo ? `<span class="ftsi-paint-order" title="Orden de pintura: ${mainPaintInfo.index}">P#${mainPaintInfo.index}</span>` : ''}
				</div>
				<span class="ftsi-badges">
					<span class="ftsi-badge">main-root</span>
					<span class="ftsi-badge ftsi-muted">${sections.length} sections</span>
				</span>
			</div>
		`;
		$list.appendChild(mainInfo);

		// Mostrar cada section
		sections.forEach((section, index) => {
			const sectionItem = document.createElement('li');
			sectionItem.className = 'ftsi-section-item';
			
			const paintInfo = STATE.tree.paintOrder.get(section);
			const sectionId = section.id || `section-${index}`;
			const isExpanded = STATE.expandedSections.has(sectionId);
			
			// Contar elementos dentro de la section
			const childElements = Array.from(section.querySelectorAll('*')).filter(shouldShowElement).length;
			const directChildren = Array.from(section.children).filter(shouldShowElement);
			
			// Información del elemento section
			const cs = getCachedStyle(section);
			const sectionBadges = [];
			if(createsStackingContext(section, cs)) sectionBadges.push('ctx');
			if(cs.position && cs.position !== 'static') sectionBadges.push(cs.position);
			if(isFlexContainer(section)) sectionBadges.push('flex-container');
			if(isGridContainer(section)) sectionBadges.push('grid-container');
			
			const selectorParts = getSelectorParts(section);
			
			sectionItem.innerHTML = `
				<div class="ftsi-section-header" data-section-id="${sectionId}">
					<div class="ftsi-section-title">
						${shortSelector(section)}
						${paintInfo ? `<span class="ftsi-paint-order" title="Orden de pintura: ${paintInfo.index}">P#${paintInfo.index}</span>` : ''}
					</div>
					<div style="display:flex;align-items:center;gap:8px">
						<span class="ftsi-section-count">${directChildren.length} hijos</span>
						<button class="ftsi-section-toggle" data-section-id="${sectionId}">
							${isExpanded ? '▼ Expandido' : '▶ Expandir'}
						</button>
					</div>
				</div>
				<div class="ftsi-section-content ${isExpanded ? 'expanded' : ''}" data-section-content="${sectionId}">
					<!-- Información del elemento section -->
					<div class="ftsi-section-info">
						${selectorParts.extra ? `<div class="ftsi-section-details">Clases: ${selectorParts.extra}</div>` : ''}
						<div class="ftsi-section-badges">
							<span class="ftsi-badge">z:${getZIndex(section, cs)}</span>
							${sectionBadges.map(b=>`<span class="ftsi-badge">${b}</span>`).join('')}
						</div>
						<div class="ftsi-section-details">
							opacity:${parseFloat(cs.opacity)}
							${paintInfo ? ` • pintado: ${paintInfo.index}°` : ''}
							${paintInfo ? ` • contexto: ${paintInfo.context}` : ''}
						</div>
					</div>
					
					<!-- Controles para la section -->
					<div class="ftsi-ctrls" style="margin-top:12px">
						<input type="number" step="1" placeholder="z-index" value="${Number.isFinite(getZIndex(section, cs)) ? getZIndex(section, cs) : ''}" data-act="z" />
						<button class="ftsi-btn" data-act="z-1">z–</button>
						<button class="ftsi-btn" data-act="z+1">z+</button>
						<input type="number" step="1" placeholder="order" value="${getCachedStyle(section, 'order') || ''}" data-act="order" />
						<button class="ftsi-btn" data-act="o-1">o–</button>
						<button class="ftsi-btn" data-act="o+1">o+</button>
						<button class="ftsi-btn" data-act="highlight">Destacar</button>
						<button class="ftsi-btn" data-act="focus">Enfocar</button>
						<button class="ftsi-btn" data-act="reset">Reset</button>
					</div>
					
					<!-- Elementos hijos -->
					<div class="ftsi-section-children" style="display:none">
						<!-- Contenido se cargará aquí -->
					</div>
				</div>
			`;
			
			// Event listeners para expandir/colapsar
			sectionItem.addEventListener('click', (e) => {
				const btn = e.target.closest('.ftsi-section-toggle');
				const header = e.target.closest('.ftsi-section-header');
				
				if(btn || header){
					const sectionId = (btn || header).dataset.sectionId;
					toggleSection(sectionId, section);
				}
				
				// Event listeners para controles de la section
				const controlBtn = e.target.closest('[data-act]');
				if(controlBtn && !btn && !header){
					const act = controlBtn.dataset.act;
					if(act === 'highlight'){ highlight(section); return; }
					if(act === 'focus'){ focusOnElement(section); return; }
					if(act === 'reset'){ resetOne(section); render(); return; }
					if(act === 'z'){
						const v = Number(controlBtn.value);
						if(Number.isFinite(v)){ setZ(section, v); render(); }
						return;
					}
					if(act === 'order'){
						const v = Number(controlBtn.value);
						if(Number.isFinite(v)){ setOrder(section, v); render(); }
						return;
					}
					if(act === 'z-1'){ bumpZ(section, -1); render(); return; }
					if(act === 'z+1'){ bumpZ(section, +1); render(); return; }
					if(act === 'o-1'){ bumpOrder(section, -1); render(); return; }
					if(act === 'o+1'){ bumpOrder(section, +1); render(); return; }
				}
			});
			
			$list.appendChild(sectionItem);
			
			// Si está expandida, cargar contenido
			if(isExpanded){
				loadSectionContent(sectionId, section);
			}
		});
		
		// Actualizar contador
		const $count = document.querySelector('[data-count]');
		if($count) $count.textContent = `${sections.length} sections`;
	}

	// Alternar sección expandida/colapsada
	function toggleSection(sectionId, section){
		const isExpanded = STATE.expandedSections.has(sectionId);
		const contentEl = document.querySelector(`[data-section-content="${sectionId}"]`);
		const toggleBtn = document.querySelector(`[data-section-id="${sectionId}"] .ftsi-section-toggle`);
		const childrenEl = document.querySelector(`[data-section-content="${sectionId}"] .ftsi-section-children`);
		
		if(isExpanded){
			STATE.expandedSections.delete(sectionId);
			contentEl.classList.remove('expanded');
			toggleBtn.textContent = '▶ Expandir';
			if(childrenEl) childrenEl.style.display = 'none';
		} else {
			STATE.expandedSections.add(sectionId);
			contentEl.classList.add('expanded');
			toggleBtn.textContent = '▼ Expandido';
			if(childrenEl) childrenEl.style.display = 'block';
			loadSectionContent(sectionId, section);
		}
	}

	// Cargar contenido de una sección
	function loadSectionContent(sectionId, section){
		const childrenEl = document.querySelector(`[data-section-content="${sectionId}"] .ftsi-section-children`);
		if(childrenEl && childrenEl.children.length > 0) return; // Ya cargado
		
		// Encontrar elementos directos de la section
		const directChildren = Array.from(section.children).filter(shouldShowElement);
		
		if(childrenEl){
			directChildren.forEach(child => {
				const childItem = createElementItem(child);
				childrenEl.appendChild(childItem);
			});
		}
	}

	// Crear item de elemento individual
	function createElementItem(el){
		const li = document.createElement('li');
		li.className = 'ftsi-item';
		
		const cs = getCachedStyle(el);
		const paintInfo = STATE.tree.paintOrder.get(el);
		const paintOrderClass = paintInfo ? (paintInfo.index < 10 ? 'low' : paintInfo.index > 50 ? 'high' : '') : '';
		
		const bad = [];
		if(createsStackingContext(el, cs)) bad.push('ctx');
		if(cs.position && cs.position !== 'static') bad.push(cs.position);
		if(isFlexItem(el)) bad.push('flex-item');
		if(isGridItem(el)) bad.push('grid-item');
		if(isFlexContainer(el)) bad.push('flex-container');
		if(isGridContainer(el)) bad.push('grid-container');

		const selectorParts = getSelectorParts(el);

		li.innerHTML = `
			<div class="ftsi-top">
				<div class="ftsi-selector">
					${selectorParts.main}
					${paintInfo ? `<span class="ftsi-paint-order ${paintOrderClass}" title="Orden de pintura: ${paintInfo.index}">P#${paintInfo.index}</span>` : ''}
				</div>
				${selectorParts.extra ? `<div class="ftsi-classes">${selectorParts.extra}</div>` : ''}
				<span class="ftsi-badges">
					<span class="ftsi-badge">z:${getZIndex(el, cs)}</span>
					${bad.map(b=>`<span class="ftsi-badge">${b}</span>`).join('')}
				</span>
			</div>
			<div class="ftsi-ctrls">
				<input type="number" step="1" placeholder="z-index" value="${Number.isFinite(getZIndex(el, cs)) ? getZIndex(el, cs) : ''}" data-act="z" />
				<button class="ftsi-btn" data-act="z-1">z–</button>
				<button class="ftsi-btn" data-act="z+1">z+</button>
				<input type="number" step="1" placeholder="order" value="${getCachedStyle(el, 'order') || ''}" data-act="order" />
				<button class="ftsi-btn" data-act="o-1">o–</button>
				<button class="ftsi-btn" data-act="o+1">o+</button>
				<button class="ftsi-btn" data-act="highlight">Destacar</button>
				<button class="ftsi-btn" data-act="focus">Enfocar</button>
				<button class="ftsi-btn" data-act="reset">Reset</button>
			</div>
			<div class="ftsi-small ftsi-muted">
				opacity:${parseFloat(cs.opacity)}
				${paintInfo ? ` • pintado: ${paintInfo.index}°` : ''}
				${paintInfo ? ` • contexto: ${paintInfo.context}` : ''}
			</div>
		`;
		
		// Event listeners
		li.addEventListener('click', (e)=>{
			const btn = e.target.closest('button, input');
			if(!btn) return;
			const act = btn.dataset.act;
			if(act === 'highlight'){ highlight(el); return; }
			if(act === 'focus'){ focusOnElement(el); return; }
			if(act === 'reset'){ resetOne(el); render(); return; }
			if(act === 'z'){
				const v = Number(btn.value);
				if(Number.isFinite(v)){ setZ(el, v); render(); }
				return;
			}
			if(act === 'order'){
				const v = Number(btn.value);
				if(Number.isFinite(v)){ setOrder(el, v); render(); }
				return;
			}
			if(act === 'z-1'){ bumpZ(el, -1); render(); return; }
			if(act === 'z+1'){ bumpZ(el, +1); render(); return; }
			if(act === 'o-1'){ bumpOrder(el, -1); render(); return; }
			if(act === 'o+1'){ bumpOrder(el, +1); render(); return; }
		});
		
		return li;
	}

	console.info('[FT] Stacking Inspector listo. Pulsa Alt/Option+Z para abrir.');
})();
JS;

	wp_add_inline_script($handle, $js);
	wp_enqueue_script($handle);
});

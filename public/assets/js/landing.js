import {createGuide,paceWheel} from './landing-tour.js?v=6';
const i18n = JSON.parse(document.getElementById('landing-i18n').textContent);
const root = document.documentElement;
const tour = document.querySelector('.overture');
const stick = tour.querySelector('.hero-stick');
const assembly = tour.querySelector('.assembly');
const canvas = assembly.querySelector('canvas');
// Present a complete first frame before asking the GPU to compile shaders.
const posterPainted = assembly.querySelector('.assembly-poster').decode().catch(()=>{}).then(()=>
    new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))
);
const hotspotGroup = assembly.querySelector('.hotspots');
const alternatePoster = assembly.querySelector('.exploded-poster');
const finale = document.querySelector('.close-scene');
const closeArt = finale.querySelector('.close-art');
const intro = tour.querySelector('.hero-intro');
const chapter = tour.querySelector('.hero-chapter');
const guide = assembly.querySelector('[data-landing-bumi]');
const quote = assembly.querySelector('#core-quote');
const caption = assembly.querySelector('[data-drawing-label]');
const motion = matchMedia('(prefers-reduced-motion: reduce)');
const tall = matchMedia('(min-height: 620px)');
const desktop = matchMedia('(min-width: 761px)');
const steps = [...document.querySelectorAll('[data-demo-panel]')];
const screens = [...document.querySelectorAll('[data-screen]')];
const rail = [...document.querySelectorAll('[data-demo-link]')];
const bench = document.querySelector('.workbench');
const menu = document.querySelector('.menu-btn');
const mobileNav = document.querySelector('.mobile-nav');
const completed = Array(steps.length).fill(false);
const replay=document.querySelector('[data-replay]');
const initialStatuses=screens.map(screen=>screen.querySelector('[data-status]').textContent);
const editorialSections=[...document.querySelectorAll('.intelligence,.people,.insights,.trust,.close-scene')];
const daySection=document.querySelector('.day');
const dayFrame=daySection.querySelector('.day-frame');
const narrator=createGuide(assembly,guide,quote,dayFrame,editorialSections,i18n);
const onScreen=new Set();
const clamp = value => Math.max(0,Math.min(value,1));
const smooth = value => { const t=clamp(value); return t*t*(3-2*t); };
let cinema = false, visible = false, renderer, loading = false, unavailable = false;
let scrollFrame = 0, drawFrame = 0, travel = 0, highlighted = -1, question = 0, currentStep = -1;
let pointerX=0, pointerY=0, yaw=0, pitch=0, lastTime=0, lastTravel=-1, exploded=false;
let heroStart=0, heroRun=1, stepTops=[], stageLine=0;
let heroVisible=false, finalVisible=false, finalTravel=0, finalStart=0, finalRun=1;
let host=assembly, idleLoad=0;
let inlineScreens=false;
let dayStart=0, stride=1, clock=0, refreshMotion=false;
const levels=new Map();
const stopWheel=paceWheel(()=>cinema);
root.classList.add('js');
guide.hidden = false;
menu.hidden = false;

function setMenu(open) {
    menu.setAttribute('aria-expanded',String(open));
    mobileNav.hidden = !open;
}
menu.addEventListener('click',()=>setMenu(mobileNav.hidden));
mobileNav.addEventListener('click',()=>setMenu(false));
document.addEventListener('keydown',event=>{
    if(event.key !== 'Escape') return;
    if(!mobileNav.hidden) { setMenu(false); menu.focus(); }
});

function showStep(index) {
    if (currentStep===index) return;
    dayFrame.dataset.direction=index<currentStep?'back':'forward';
    currentStep = index;
    dayFrame.dataset.step=index;
    screens.forEach((screen,i)=>{
        const selected=i===index;
        screen.hidden=!cinema && !inlineScreens && !selected;
        screen.dataset.active=steps[i].dataset.active=String(selected);
        steps[i].inert=cinema && !selected;
        screen.inert=cinema && !selected;
        if(i===index) rail[i].setAttribute('aria-current','step');
        else rail[i].removeAttribute('aria-current');
    });
}
function chooseChapter(next) {
    exploded=next;
    intro.inert=cinema && next;
    chapter.inert=cinema && !next;
    hotspotGroup.inert=!next;
    caption.textContent = next ? i18n.captionLinks : i18n.captionCore;
}
function draw(time) {
    drawFrame=0;
    if (!visible || document.hidden || !renderer || motion.matches) return;
    const dt=lastTime ? Math.min(time-lastTime,50) : 16;
    lastTime=time;
    const gain=1-Math.exp(-dt/60);
    yaw+=(pointerX-yaw)*gain;
    pitch+=(pointerY-pitch)*gain;
    const inHero=host===assembly;
    renderer.render(inHero?travel:finalTravel,inHero?yaw*.17:0,inHero?pitch*.12:0,inHero?highlighted:-1);
    guide.style.setProperty('--look',(yaw*7)+'deg');
    guide.style.setProperty('--eyes-x',(yaw*9)+'px');
    guide.style.setProperty('--eyes-y',(pitch*7)+'px');
    if(Math.abs(pointerX-yaw)+Math.abs(pointerY-pitch)>.002) requestDraw();
    else lastTime=0;
}
function requestDraw() {
    if(visible && renderer && !motion.matches && !document.hidden && !drawFrame) drawFrame=requestAnimationFrame(draw);
}
async function enableRenderer() {
    if(!visible || motion.matches || document.hidden || loading || unavailable) return;
    if(renderer) { requestDraw(); return; }
    loading=true;
    try {
        await posterPainted;
        if(!visible || motion.matches || document.hidden) return;
        const {createScene}=await import('./landing-scene.js?v=3');
        renderer=createScene(canvas);
        unavailable=!renderer;
        if (renderer && !motion.matches && visible) {
            renderer.render(host===assembly?travel:finalTravel);
            host.classList.add('is-rendered');
        } else if (!renderer) {
            loadAlternatePoster();
        }
    } catch { unavailable=true;loadAlternatePoster(); }
    finally { loading=false; }
}
function refreshVisibility() {
    visible=heroVisible || finalVisible;
    const nextHost=finalVisible ? closeArt : assembly;
    if(host!==nextHost && visible) {
        host.classList.remove('is-rendered');
        host=nextHost;
        host.append(canvas);
    }
    const active=visible && !document.hidden && !motion.matches;
    assembly.style.setProperty('--idle',active && heroVisible?'running':'paused');
    if(active && renderer) {
        host.classList.add('is-rendered');
        requestDraw();
    } else if(active && !idleLoad) {
        const start=()=>{idleLoad=0;enableRenderer();};
        idleLoad=window.requestIdleCallback ? requestIdleCallback(start,{timeout:450}) : setTimeout(start,32);
    }
    else { cancelAnimationFrame(drawFrame);drawFrame=0;lastTime=0; }
}
function loadAlternatePoster() {
    if(!alternatePoster.hasAttribute('src')) alternatePoster.src=alternatePoster.dataset.explodedSrc;
    alternatePoster.hidden=false;
}
const visibilityObserver=new IntersectionObserver(entries=>{
    for(const entry of entries) {
        if(entry.target===assembly) heroVisible=entry.isIntersecting;
        else finalVisible=entry.isIntersecting;
    }
    refreshVisibility();
});
visibilityObserver.observe(assembly);
visibilityObserver.observe(finale);
const editorialObserver=new IntersectionObserver(entries=>{
    for(const entry of entries) {
        if(entry.isIntersecting) onScreen.add(entry.target);
        else {
            onScreen.delete(entry.target);
            entry.target.style.setProperty('--entry',entry.boundingClientRect.top<0?1:0);
        }
    }
    scheduleScroll();
});
editorialSections.forEach(section=>editorialObserver.observe(section));
document.addEventListener('visibilitychange',refreshVisibility);
canvas.addEventListener('webglcontextlost',event=>{
    event.preventDefault();
    renderer=null;
    unavailable=true;
    host.classList.remove('is-rendered');
    loadAlternatePoster();
});
canvas.addEventListener('webglcontextrestored',()=>{unavailable=false;enableRenderer();});
window.addEventListener('pagehide',event=>{
    if (!event.persisted) renderer?.dispose();
});
assembly.addEventListener('pointermove',event=>{
    if(motion.matches || event.pointerType!=='mouse') return;
    const box=assembly.getBoundingClientRect();
    pointerX=(event.clientX-box.left)/box.width*2-1;
    pointerY=(event.clientY-box.top)/box.height*2-1;
    requestDraw();
},{passive:true});
assembly.addEventListener('pointerleave',()=>{pointerX=0;pointerY=0;requestDraw();});
for (const link of document.querySelectorAll('[data-module]')) {
    const highlight=on=>{
        highlighted=on?Number(link.dataset.module):-1;
        caption.textContent=on?link.textContent.trim():(exploded?i18n.captionLinks:i18n.captionCore);
        requestDraw();
    };
    link.addEventListener('pointerenter',()=>highlight(true));
    link.addEventListener('pointerleave',()=>highlight(false));
    link.addEventListener('focus',()=>highlight(true));
    link.addEventListener('blur',()=>highlight(false));
}

function syncScroll(now=performance.now(),snap=false) {
    scrollFrame=0;
    const dt=clock?Math.min(34,now-clock):16;
    clock=now;
    refreshMotion=false;
    function paced(key,target) {
        const previous=levels.get(key)??target;
        const difference=target-previous;
        const arrived=snap || !cinema || Math.abs(difference)<.001;
        const amount=arrived?target:previous+Math.sign(difference)*Math.min(Math.abs(difference)*(1-Math.exp(-dt/64)),dt*.00185);
        levels.set(key,amount);
        if(!arrived) refreshMotion=true;
        return amount;
    }
    if(!motion.matches) {
        for(const section of onScreen) {
            const rect=section.parentElement.getBoundingClientRect();
            const phase=(innerHeight-rect.top)/(innerHeight*.85+Math.max(0,rect.height-section.offsetHeight));
            const amount=paced(section,smooth(phase));
            section.style.setProperty('--entry',amount.toFixed(3));
        }
    }
    const finish=paced('final',1-smooth((scrollY-finalStart)/finalRun));
    if(finalTravel!==finish) {
        finalTravel=finish;
        if(host===closeArt) requestDraw();
    }
    travel=paced('hero',cinema ? clamp((scrollY-heroStart)/heroRun) : 0);
    if(lastTravel!==travel) {
        const out=smooth((travel-.13)/.30), into=smooth((travel-.32)/.33);
        stick.style.setProperty('--intro-alpha',1-out);
        stick.style.setProperty('--intro-y',(-25*out)+'px');
        stick.style.setProperty('--chapter-alpha',into);
        stick.style.setProperty('--node-alpha',smooth((travel-.49)/.25));
        stick.style.setProperty('--chapter-y',(25*(1-into))+'px');
        stick.style.setProperty('--scale-x',(98*travel)+'%');
        stick.style.setProperty('--shadow',1-.24*travel);
        if(exploded!==(travel>.36)) chooseChapter(travel>.36);
        lastTravel=travel;
        requestDraw();
    }
    const y=scrollY+stageLine+2;
    let active=0;
    if(cinema) {
        const phase=paced('day',clamp((scrollY-dayStart)/(stride*4)))*4;
        active=Math.min(3,Math.floor(phase+.12));
        dayFrame.style.setProperty('--beat',clamp(phase-active+.12).toFixed(3));
    } else {
        for(let i=1;i<stepTops.length;i++) if(y>=stepTops[i]) active=i;
    }
    showStep(active);
    narrator.update(scrollY,travel,active,dayStart);
    if(refreshMotion) scheduleScroll();
    else clock=0;
}
function scheduleScroll() {
    if(!scrollFrame) scrollFrame=requestAnimationFrame(syncScroll);
}
function measure() {
    const compact=!desktop.matches && !tall.matches;
    if(compact!==inlineScreens) {
        inlineScreens=compact;
        root.classList.toggle('inline-screens',compact);
        const body=bench.querySelector('.workbench-body');
        screens.forEach((screen,i)=>{
            if(compact) steps[i].insertBefore(screen,steps[i].querySelector('button'));
            else body.append(screen);
        });
        currentStep=-1;
    }
    const enable=tall.matches && !motion.matches;
    if(cinema!==enable) currentStep=-1;
    cinema=enable;
    root.classList.toggle('cinema',cinema);
    const navbar=document.querySelector('.mast-inner').offsetHeight;
    heroStart=scrollY+tour.getBoundingClientRect().top-navbar;
    heroRun=Math.max(1,tour.offsetHeight-stick.offsetHeight);
    finalStart=scrollY+finale.parentElement.getBoundingClientRect().top-innerHeight*.53;
    finalRun=Math.max(1,Math.min(innerHeight*.84,document.documentElement.scrollHeight-innerHeight-finalStart));
    dayStart=scrollY+daySection.getBoundingClientRect().top-navbar;
    stride=Math.max(1,(daySection.offsetHeight-dayFrame.offsetHeight)/3.65);
    stepTops=steps.map((step,i)=>cinema?dayStart+i*stride:scrollY+step.getBoundingClientRect().top);
    stageLine=desktop.matches ? Math.max(navbar+20,innerHeight*.38) :
        (tall.matches ? navbar+bench.offsetHeight+34 : navbar+20);
    lastTravel=-1;
    chooseChapter(cinema && travel>.36);
    narrator.measure(cinema);
    if(desktop.matches) setMenu(false);
    syncScroll();
    requestDraw();
}
function syncPreference() {
    if(motion.matches) host.classList.remove('is-rendered');
    measure(); refreshVisibility();
}
window.addEventListener('scroll',scheduleScroll,{passive:true});
window.addEventListener('resize',measure,{passive:true});
motion.addEventListener('change',syncPreference);
new ResizeObserver(measure).observe(document.querySelector('.day-layout'));
const chapterSizes=new ResizeObserver(measure);
editorialSections.forEach(panel=>chapterSizes.observe(panel));
document.fonts.ready.then(measure);
measure();
if (!motion.matches) assembly.classList.add('hero-in');

function destination(hash) {
    if(hash==='#ecosystem' && cinema) return heroStart+heroRun*.88;
    const step=steps.findIndex(item=>'#'+item.id===hash);
    if(step>=0) return Math.max(0,cinema?stepTops[step]+stride*.12:stepTops[step]-stageLine+12);
    if(cinema && hash==='#demo') return dayStart;
    const panel=editorialSections.find(section=>'#'+section.id===hash);
    return cinema && panel ? scrollY+panel.parentElement.getBoundingClientRect().top-document.querySelector('.mast-inner').offsetHeight : null;
}
function follow(hash,moveFocus=false,instant=false) {
    const top=destination(hash);
    if (top===null) return false;
    stopWheel();
    if (instant || motion.matches) {
        window.scrollTo({top,behavior:'instant'});
        cancelAnimationFrame(scrollFrame);
        syncScroll(performance.now(),true);
    } else {
        // Плавный переход: scroll-события сами двигают --entry, snap здесь
        // вызвал бы рывок назад, пока levels ещё держат старую позицию.
        window.scrollTo({top,behavior:'smooth'});
    }
    if(moveFocus) {
        const target=document.getElementById(hash.slice(1));
        target.tabIndex=-1;
        target.focus({preventScroll:true});
    }
    return true;
}
document.addEventListener('click',event=>{
    const link=event.target.closest('a[href^="#"]');
    if(!link || event.defaultPrevented || event.button!==0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const hash=link.hash;
    if(destination(hash)===null) return;
    event.preventDefault();
    if(location.hash!==hash) history.pushState(null,'',hash);
    follow(hash,true);
});
window.addEventListener('hashchange',()=>follow(location.hash));
window.addEventListener('pageshow',()=>{measure();follow(location.hash,false,true);});
if(location.hash) follow(location.hash,false,true);

function answer(index) {
    if(index===1) return completed[0] ? i18n.answers.bothOnShift : i18n.answers.faridRemote;
    if(index===2) {
        const events=i18n.events.filter((_,i)=>completed[i]);
        return events.length ? i18n.answers.demoPrefix+' '+events.join(', ')+'.' : i18n.answers.dayStarted;
    }
    return (completed[2]?i18n.answers.estimateSigned:i18n.answers.estimateWaiting)+' '+(completed[1]?i18n.answers.teamWorking:i18n.answers.taskPlanned);
}
const prompts=[...document.querySelectorAll('[data-prompt]')];
function ask(index) {
    question=index;
    document.querySelector('[data-question]').textContent=prompts[index].textContent;
    document.querySelector('[data-answer]').textContent=answer(index);
    prompts.forEach((prompt,i)=>prompt.setAttribute('aria-pressed',String(i===index)));
}
prompts.forEach((button,index)=>button.addEventListener('click',()=>{
    ask(index);
    narrator.react(i18n.reactions.checked,'curious');
    if(!motion.matches) document.querySelector('.bot-line').animate([{opacity:.35,transform:'translateY(6px)'},{opacity:1,transform:'none'}],{duration:280});
}));
steps.forEach((step,index)=>{
    const button=step.querySelector('[data-demo-action]');
    button.hidden=false;
    button.addEventListener('click',()=>{
        if(completed[index]) return;
        completed[index]=true;
        screens[index].dataset.complete='true';
        narrator.react(i18n.reactions.steps[index]);
        replay.hidden=false;
        showStep(index);
        screens[index].querySelector('[data-status]').textContent=button.dataset.result;
        step.querySelector('[data-receipt]').textContent=button.dataset.result;
        button.disabled=true;
        if(index===0) document.querySelector('[data-attendance]').textContent='2';
        if(index===1) document.querySelector('[data-in-progress]').append(document.querySelector('[data-task]'));
        const total=completed.slice(0,3).filter(Boolean).length;
        document.querySelector('[data-completed]').textContent=String(total);
        document.querySelectorAll('.summary-bars i').forEach((bar,i)=>bar.classList.toggle('done',completed[i]));
        ask(question);
    });
});
replay.addEventListener('click',()=>{
    completed.fill(false);
    steps.forEach((step,i)=>{
        step.querySelector('[data-demo-action]').disabled=false;
        step.querySelector('[data-receipt]').textContent='';
        screens[i].querySelector('[data-status]').textContent=initialStatuses[i];
        delete screens[i].dataset.complete;
    });
    document.querySelector('.kanban > div').append(document.querySelector('[data-task]'));
    document.querySelector('[data-attendance]').textContent='1';
    document.querySelector('[data-completed]').textContent='0';
    document.querySelectorAll('.summary-bars i').forEach(bar=>bar.classList.remove('done'));
    ask(question);
    replay.hidden=true;
    history.replaceState(null,'','#'+steps[0].id);
    follow('#'+steps[0].id,true);
});
const weeks=[[5,9,7,11,10],[8,5,10,6,9]];
const bars=[...document.querySelectorAll('[data-bar]')];
const periods=[...document.querySelectorAll('[data-period]')];
let period=0, day=3;
function updateChart() {
    const data=weeks[period];
    document.querySelector('[data-chart-total]').textContent=data.reduce((sum,n)=>sum+n,0);
    bars.forEach((bar,i)=>{
        bar.style.setProperty('--height',(100*data[i]/12)+'%');
        bar.setAttribute('aria-pressed',String(day===i));
        bar.setAttribute('aria-label',bar.dataset.day+' — '+data[i]);
        bar.dataset.count=String(data[i]);
    });
    periods.forEach((btn,i)=>btn.setAttribute('aria-pressed',String(period===i)));
    document.querySelector('[data-chart-detail]').textContent=bars[day].dataset.day+' · '+i18n.chartDone+' '+data[day];
}
periods.forEach((button,i)=>button.addEventListener('click',()=>{period=i;updateChart();}));
bars.forEach((button,i)=>button.addEventListener('click',()=>{day=i;updateChart();}));
updateChart();
const access=[...document.querySelectorAll('[data-access]')];
access.forEach((button,index)=>button.addEventListener('click',()=>{
    access.forEach((item,i)=>item.setAttribute('aria-pressed',String(index===i)));
    const line=i18n.accessLines[index];
    document.querySelector('[data-pass-title]').textContent=i18n.accessTitles[index];
    document.querySelector('[data-pass-scope]').textContent=line;
    narrator.react(line,'wink');
}));
document.querySelectorAll('.department').forEach(dept=>dept.addEventListener('toggle',()=>{
    if(dept.open && dept.matches(':focus-within')) narrator.react(i18n.reactions.department,'curious');
}));

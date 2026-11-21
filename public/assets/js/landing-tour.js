const clamp=(v,a=0,b=1)=>Math.max(a,Math.min(b,v));

// Limit physical wheel travel per frame and discard excess momentum when direction changes.
export function paceWheel(enabled) {
    let frame=0, last=0, remaining=0, carry=0, applied=0;
    const stop=()=>{cancelAnimationFrame(frame);frame=0;last=0;remaining=0;carry=0;};
    function tick(time) {
        frame=0;
        if(!enabled() || document.hidden || Math.abs(scrollY-applied)>2) {stop();return;}
        const dt=last?Math.min(32,time-last):16;
        last=time;
        const distance=Math.sign(remaining)*Math.min(Math.abs(remaining),innerHeight*.00165*dt,Math.abs(remaining)*(1-Math.exp(-dt/68)));
        remaining-=distance;
        carry+=distance;
        const pixels=Math.trunc(carry);
        if(pixels) {carry-=pixels;window.scrollBy({top:pixels,behavior:'instant'});applied=scrollY;}
        if(Math.abs(remaining)>.55) frame=requestAnimationFrame(tick);
        else stop();
    }
    window.addEventListener('wheel',event=>{
        if(!enabled() || !event.cancelable || event.ctrlKey || event.metaKey || event.shiftKey || event.defaultPrevented || Math.abs(event.deltaX)>Math.abs(event.deltaY)) return;
        const delta=event.deltaY*(event.deltaMode===1?16:event.deltaMode===2?innerHeight:1);
        if(!delta || event.target.closest?.('select,textarea,input,dialog,[data-native-scroll]')) return;
        if((delta<0 && scrollY<=0)||(delta>0 && scrollY+innerHeight>=document.documentElement.scrollHeight-1)) return;
        event.preventDefault();
        if(Math.sign(remaining)!==Math.sign(delta)) {remaining=0;carry=0;}
        remaining=clamp(remaining+delta,-innerHeight*.8,innerHeight*.8);
        if(!frame) {applied=scrollY;frame=requestAnimationFrame(tick);}
    },{passive:false});
    for(const type of ['pointerdown','touchstart','keydown','pagehide']) window.addEventListener(type,stop,{passive:true});
    return stop;
}

export function createGuide(assembly,button,quote,dayFrame,sections,i18n) {
    const chapters=i18n.chapters;
    const home=document.createComment('guide');
    button.before(home);
    const narrator=document.createElement('aside');
    narrator.className='tour-narrator';
    narrator.setAttribute('aria-label',i18n.guideLabel);
    document.body.append(narrator);
    const stops=[dayFrame,...sections].map(host=>{
        const stop=document.createElement('span');
        stop.className='guide-stop';stop.setAttribute('aria-hidden','true');host.append(stop);
        return stop;
    });
    const runs=sections.map(section=>{
        const run=document.createElement('div');
        run.className='chapter-run';section.before(run);run.append(section);return run;
    });
    let touring=false,current=-1,x=NaN,y=NaN,tx=0,ty=0,frame=0,time=0;
    let timer,textTimer,dismissed=false,starts=[];
    function close() {
        clearTimeout(timer); clearTimeout(textTimer);
        narrator.dataset.speaking='false';button.setAttribute('aria-expanded','false');
        if(!touring) quote.hidden=true;
    }
    function speak(mood,line) {
        clearTimeout(timer);clearTimeout(textTimer);
        narrator.dataset.mood=mood;button.dataset.mood=mood;quote.hidden=false;
        if(touring) {
            narrator.dataset.speaking='false';
            textTimer=setTimeout(()=>{
                quote.textContent=line;
                if(dismissed) return;
                narrator.dataset.speaking='true';button.setAttribute('aria-expanded','true');
                timer=setTimeout(close,Math.max(4400,line.length*65)+700);
            },120);
        } else {quote.textContent=line;button.setAttribute('aria-expanded','true');}
    }
    button.addEventListener('click',()=>{
        if(button.getAttribute('aria-expanded')==='true') {dismissed=true;close();}
        else {dismissed=false;const [,mood,line]=chapters[Math.max(0,current)];speak(mood,line);}
    });
    document.addEventListener('keydown',e=>{
        if(e.key==='Escape' && button.getAttribute('aria-expanded')==='true') {dismissed=true;close();button.focus({preventScroll:true});}
    });
    function move(now) {
        frame=0;
        if(!touring || document.hidden) return;
        const dt=time?Math.min(now-time,32):16;
        time=now;
        const dx=tx-x,dy=ty-y,d=Math.hypot(dx,dy);
        if(d<.5) {x=tx;y=ty;time=0;}
        else {
            const gain=Math.min(1-Math.exp(-dt/95),dt*Math.max(.9,innerWidth*.00165)/d);
            x+=dx*gain;y+=dy*gain;
        }
        narrator.style.transform='translate3d('+x.toFixed(1)+'px,'+y.toFixed(1)+'px,0)';
        narrator.dataset.side=x>innerWidth*.56?'left':'right';
        if(d>=.5) frame=requestAnimationFrame(move);
    }
    function measure(enable) {
        if(touring!==enable) {
            touring=enable;close();quote.hidden=true;
            if(enable) narrator.append(button,quote);
            else {home.after(button,quote);cancelAnimationFrame(frame);frame=0;x=NaN;}
            narrator.hidden=!enable;current=-1;
        }
        starts=runs.map(run=>scrollY+run.getBoundingClientRect().top);
        sections.forEach(panel=>{
            const offset=Math.min(document.querySelector('.mast-inner').offsetHeight,innerHeight-panel.offsetHeight);
            panel.style.setProperty('--pin',offset+'px');
        });
    }
    function update(pos,travel,step,dayStart) {
        if(!touring) return;
        const line=pos+innerHeight*.43;
        let index=travel>.42?1:0,stop=null;
        if(line>=dayStart) {index=2+step;stop=stops[0];}
        for(let i=0;i<starts.length;i++) {
            if(line>=starts[i]) {index=6+i;stop=stops[i+1];}
        }
        const bounds=(stop||assembly).getBoundingClientRect();
        const size=narrator.offsetWidth;
        tx=clamp(bounds.left+bounds.width*.5-size/2,4,innerWidth-size-4);
        ty=clamp(bounds.top+bounds.height*.5-size/2,60,innerHeight-size-5);
        if(!Number.isFinite(x)) {x=tx;y=ty;}
        if(!frame) frame=requestAnimationFrame(move);
        if(index!==current) {
            current=index;dismissed=false;
            const [name,mood,lineText]=chapters[index];narrator.dataset.chapter=name;speak(mood,lineText);
        }
    }
    document.addEventListener('visibilitychange',()=>{if(!document.hidden && touring && !frame) frame=requestAnimationFrame(move);});
    return {measure,update,react(line,mood='happy'){if(touring){dismissed=false;speak(mood,line);}},runs};
}

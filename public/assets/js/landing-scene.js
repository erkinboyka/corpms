// Four machined bands share one indexed mesh and one draw call. The scroll position
// drives both the exploded assembly and the camera, in either direction.
const vertex = `
attribute vec3 position;
attribute vec3 normal;
attribute vec2 parameter;
attribute float band;
uniform float travel, aspect, yaw, pitch;
varying vec3 vNormal, vPoint;
varying vec2 vUv;
varying float vBand;
mat2 turn(float angle) {
    float c = cos(angle), s = sin(angle);
    return mat2(c,s,-s,c);
}
void main() {
    float a = band;
    float expand = smoothstep(.04, .93, travel);
    float ax = mix(.26 + a * .41, 1.18, expand);
    float az = mix(-.66 + a * .12, -.14, expand);
    float ay = mix(-.32 + a * .51, .50, expand);
    vec3 p = position, n = normal;
    p.yz = turn(ax)*p.yz; n.yz = turn(ax)*n.yz;
    p.xz = turn(ay)*p.xz; n.xz = turn(ay)*n.xz;
    p.xy = turn(az)*p.xy; n.xy = turn(az)*n.xy;
    p.y += (1.5-a)*.88*expand;
    float orbit = travel*.76 + yaw;
    p.xz = turn(orbit)*p.xz; n.xz = turn(orbit)*n.xz;
    p.yz = turn(pitch-.09*expand)*p.yz; n.yz=turn(pitch-.09*expand)*n.yz;
    float z = 6.8-p.z;
    float scale = mix(2.56,2.08,expand);
    gl_Position = vec4(p.x*scale/aspect, p.y*scale, z*1.02-.38, z);
    vNormal=n; vPoint=p; vBand=band; vUv=parameter;
}`;
const fragment = `
precision mediump float;
varying vec3 vNormal,vPoint;
varying vec2 vUv;
varying float vBand;
uniform float selected;
void main() {
    float angle = vUv.x;
    float ridge = cos(angle*124.);
    vec3 tangent = normalize(cross(normalize(vNormal),vec3(.02,1.,.01)) + vec3(.001));
    vec3 n = normalize(vNormal + tangent*ridge*.021);
    vec3 eye = normalize(vec3(0.,0.,6.8)-vPoint);
    vec3 r = reflect(-eye,n);
    float fresnel = pow(1.-max(0.,dot(n,eye)),2.5);
    float horizon = smoothstep(-.38,.5,r.y);
    float strip = exp(-pow((r.x*.82+r.y*.44+.13)*5.9,2.));
    float strip2 = exp(-pow((r.y-r.z*.36-.52)*11.,2.));
    float edge = smoothstep(.67, .99, abs(sin(vUv.y)));
    vec3 base = vec3(.045,.36,.255);
    if(vBand>.5) base=vec3(.21,.57,.445);
    if(vBand>1.5) base=vec3(.095,.35,.305);
    if(vBand>2.5) base=vec3(.56,.63,.56);
    float milled = mix(.92,1.035,smoothstep(-.65,.65,ridge));
    vec3 metal = base*(.31+.92*horizon)*milled + vec3(.065,.10,.082)*edge;
    metal += strip * vec3(.41,.53,.47) + strip2*vec3(.36,.48,.41);
    metal += fresnel*vec3(.24,.35,.30);
    metal += (1.-smoothstep(.05,.75,abs(vBand-selected)))*vec3(.035,.09,.063);
    gl_FragColor=vec4(metal,1.);
}`;

function makeBands() {
    const data = [], indices = [];
    const rings = 232, sides = 20;
    const rounded = t => Math.sign(t) * Math.pow(Math.abs(t), .61);
    for (let band = 0; band < 4; band++) {
        const start = data.length / 9;
        const radius = 2.05 - band * .225;
        for (let i = 0; i <= rings; i++) {
            const a = i * 2 * Math.PI / rings;
            const ca = Math.cos(a), sa = Math.sin(a);
            for (let j = 0; j <= sides; j++) {
                const b = j * 2 * Math.PI / sides, cb = Math.cos(b), sb = Math.sin(b);
                const r = radius + .151*rounded(cb);
                const nx = Math.sign(cb)*Math.pow(Math.abs(cb),1.39)/.151;
                const nz = Math.sign(sb)*Math.pow(Math.abs(sb),1.39)/.22;
                const length = Math.hypot(nx,nz);
                data.push(r*ca,r*sa,.22*rounded(sb),ca*nx/length,sa*nx/length,nz/length,a,b,band);
                if(i < rings && j < sides) {
                    const k = start + i*(sides+1)+j;
                    indices.push(k,k+sides+1,k+1,k+1,k+sides+1,k+sides+2);
                }
            }
        }
    }
    return { data:new Float32Array(data), indices:new Uint16Array(indices) };
}

export function createScene(canvas) {
    const gl = canvas.getContext('webgl', { alpha:true, antialias:true, powerPreference:'low-power' });
    if (!gl) return null;
    const program=gl.createProgram();
    const buffers=[];
    const cleanup = () => {
        buffers.forEach(buffer => gl.deleteBuffer(buffer));
        gl.deleteProgram(program);
    };
    for (const [type,source] of [[gl.VERTEX_SHADER,vertex],[gl.FRAGMENT_SHADER,fragment]]) {
        const shader=gl.createShader(type);
        gl.shaderSource(shader,source);
        gl.compileShader(shader);
        if (!gl.getShaderParameter(shader,gl.COMPILE_STATUS)) {
            gl.deleteShader(shader);
            cleanup();
            return null;
        }
        gl.attachShader(program,shader);
        gl.deleteShader(shader);
    }
    gl.linkProgram(program);
    if (!gl.getProgramParameter(program,gl.LINK_STATUS)) { cleanup(); return null; }
    gl.useProgram(program);
    const mesh = makeBands();
    for (const [target,data] of [[gl.ARRAY_BUFFER,mesh.data],[gl.ELEMENT_ARRAY_BUFFER,mesh.indices]]) {
        const buffer=gl.createBuffer();
        buffers.push(buffer);
        gl.bindBuffer(target,buffer);
        gl.bufferData(target,data,gl.STATIC_DRAW);
    }
    for (const [name,size,offset] of [['position',3,0],['normal',3,3],['parameter',2,6],['band',1,8]]) {
        const id=gl.getAttribLocation(program,name);
        gl.enableVertexAttribArray(id);
        gl.vertexAttribPointer(id,size,gl.FLOAT,false,36,offset*4);
    }
    const u=Object.fromEntries(['travel','aspect','yaw','pitch','selected'].map(n=>[n,gl.getUniformLocation(program,n)]));
    const count=mesh.indices.length;
    gl.enable(gl.DEPTH_TEST);
    gl.clearColor(0,0,0,0);
    let width=0, height=0;
    return {
        render(travel, yaw=0, pitch=0, selected=-1) {
            const dpr=Math.min(devicePixelRatio || 1, canvas.clientWidth < 580 ? 1.35 : 1.65);
            const w=Math.round(canvas.clientWidth*dpr), h=Math.round(canvas.clientHeight*dpr);
            if (!w || !h) return;
            if (w!==width || h!==height) {
                width=canvas.width=w; height=canvas.height=h;
                gl.viewport(0,0,w,h);
            }
            gl.uniform1f(u.aspect,w/h);
            gl.uniform1f(u.travel,travel);
            gl.uniform1f(u.yaw,yaw);
            gl.uniform1f(u.pitch,pitch);
            gl.uniform1f(u.selected,selected);
            gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
            gl.drawElements(gl.TRIANGLES,count,gl.UNSIGNED_SHORT,0);
        },
        dispose:cleanup,
    };
}

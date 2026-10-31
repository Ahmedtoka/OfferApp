// The landing's stage: four phones showing the product, turning as a carousel with the scroll, beside a solid
// disc and a ring (the logo's two pieces). The screens are the page's own inline SVGs drawn to textures, so the
// 3D phones and the static fallback can never show different things.

const COBALT = 0x1a2ae6;
const SUN = 0xffe100;
const PHONE_W = 2.8;
const PHONE_H = 5.6;
const VIEW_H = 7.5; // World height visible at z = 0: a phone fills 75% of it, like the static .chapter__phone.
const FOV = 30;
const CAMERA_Z = VIEW_H / 2 / Math.tan((FOV * Math.PI) / 360);

const smooth = (t) => t * t * t * (t * (t * 6 - 15) + 10);
const clamp01 = (t) => Math.min(1, Math.max(0, t));
const mix = (a, b, t) => a + (b - a) * t;

function roundedRect(THREE, w, h, r) {
  const x = -w / 2;
  const y = -h / 2;
  const s = new THREE.Shape();
  s.moveTo(x + r, y);
  s.lineTo(x + w - r, y);
  s.quadraticCurveTo(x + w, y, x + w, y + r);
  s.lineTo(x + w, y + h - r);
  s.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
  s.lineTo(x + r, y + h);
  s.quadraticCurveTo(x, y + h, x, y + h - r);
  s.lineTo(x, y + r);
  s.quadraticCurveTo(x, y, x + r, y);
  return s;
}

/** The page font as a data URL, so an SVG drawn as an image can use it. Falls back to system fonts on failure. */
async function fontCss(url) {
  try {
    const bytes = new Uint8Array(await (await fetch(url)).arrayBuffer());
    let binary = '';
    for (let i = 0; i < bytes.length; i += 8192) binary += String.fromCharCode(...bytes.subarray(i, i + 8192));
    return `@font-face{font-family:Alexandria;font-weight:100 900;src:url(data:font/woff2;base64,${btoa(binary)}) format("woff2")}`;
  } catch {
    return '';
  }
}

async function svgTexture(THREE, svg, css, anisotropy) {
  const clone = svg.cloneNode(true);
  clone.setAttribute('width', '780');
  clone.setAttribute('height', '1600');
  if (css) {
    const style = document.createElementNS('http://www.w3.org/2000/svg', 'style');
    style.textContent = css;
    clone.prepend(style);
  }
  const img = new Image();
  img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(clone));
  await img.decode();
  const canvas = document.createElement('canvas');
  canvas.width = 780;
  canvas.height = 1600;
  canvas.getContext('2d').drawImage(img, 0, 0, 780, 1600);
  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  texture.anisotropy = anisotropy;
  return texture;
}

function buildPhone(THREE, texture, bodyColor) {
  const group = new THREE.Group();
  const depth = 0.14;
  const bevel = 0.04;
  const bodyGeo = new THREE.ExtrudeGeometry(roundedRect(THREE, PHONE_W - 2 * bevel, PHONE_H - 2 * bevel, 0.34), {
    depth, bevelEnabled: true, bevelSize: bevel, bevelThickness: bevel, bevelSegments: 4, curveSegments: 16,
  });
  bodyGeo.translate(0, 0, -depth / 2);
  group.add(new THREE.Mesh(bodyGeo, new THREE.MeshStandardMaterial({ color: bodyColor, roughness: 0.42, metalness: 0.08 })));

  const sw = PHONE_W - 0.2;
  const sh = PHONE_H - 0.2;
  const screenGeo = new THREE.ShapeGeometry(roundedRect(THREE, sw, sh, 0.27), 16);
  const pos = screenGeo.attributes.position;
  const uv = screenGeo.attributes.uv;
  for (let i = 0; i < pos.count; i++) uv.setXY(i, (pos.getX(i) + sw / 2) / sw, (pos.getY(i) + sh / 2) / sh);
  const screen = new THREE.Mesh(screenGeo, new THREE.MeshBasicMaterial({ map: texture, toneMapped: false }));
  screen.position.z = depth / 2 + bevel + 0.004;
  group.add(screen);
  return group;
}

export async function start(stage) {
  const THREE = await import(stage.dataset.three);
  const root = document.documentElement;
  const canvas = stage.querySelector('canvas');
  const story = document.querySelector('[data-story]');
  const beats = [...document.querySelectorAll('[data-beat]')];
  const rtlSign = root.dir === 'rtl' ? -1 : 1;

  const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'low-power' });
  renderer.setClearColor(0x000000, 0);

  const scene = new THREE.Scene();
  scene.fog = new THREE.Fog(COBALT, CAMERA_Z + 1, CAMERA_Z + 9);
  const camera = new THREE.PerspectiveCamera(FOV, 1, 0.1, 60);
  camera.position.z = CAMERA_Z;
  scene.add(new THREE.AmbientLight(0xffffff, 2));
  const key = new THREE.DirectionalLight(0xffffff, 2.2);
  key.position.set(-3, 5, 8);
  scene.add(key);

  const css = await fontCss(stage.dataset.font);
  const anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
  const svgs = ['settings', 'test', 'cart', 'analytics'].map((n) => document.querySelector(`svg[data-screen="${n}"]`));
  const textures = await Promise.all(svgs.map((svg) => svgTexture(THREE, svg, css, anisotropy)));

  const rig = new THREE.Group();
  scene.add(rig);
  const phones = textures.map((t, i) => {
    const phone = buildPhone(THREE, t, i % 2 ? SUN : 0xffffff);
    rig.add(phone);
    return phone;
  });

  // The logo's two pieces.
  const disc = new THREE.Mesh(new THREE.CylinderGeometry(0.72, 0.72, 0.2, 64), new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.35 }));
  disc.geometry.rotateX(Math.PI / 2);
  const ring = new THREE.Mesh(new THREE.TorusGeometry(0.56, 0.2, 24, 64), new THREE.MeshStandardMaterial({ color: SUN, roughness: 0.35 }));
  rig.add(disc, ring);

  // Where each phone rests while the hero is on screen: the settings and cart screens as a front pair.
  const HERO = [
    { x: -1.3, y: 0.05, z: 0, ry: 0.42, rz: -0.07 },
    { x: -2.9, y: 0.9, z: -3.4, ry: 0.5, rz: 0.03 },
    { x: 1.25, y: -0.3, z: 0.5, ry: -0.36, rz: 0.06 },
    { x: 3.3, y: 0.9, z: -3.6, ry: -0.5, rz: -0.03 },
  ];

  let wide = true;
  let viewW = VIEW_H;
  function resize() {
    const w = canvas.clientWidth;
    const h = canvas.clientHeight;
    if (!w || !h) return;
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    viewW = VIEW_H * camera.aspect;
    wide = w > 760 && camera.aspect > 1; // The same breakpoint as site.css.
  }
  resize();
  const ro = new ResizeObserver(resize);
  ro.observe(canvas);

  // Scroll position as beats: 0 is the hero, 1 to 4 are the chapters. The ease holds each phone still at rest.
  function scrollBeat() {
    const y = window.scrollY;
    let beat = 0;
    for (let i = 0; i < beats.length - 1; i++) {
      const a = beats[i].offsetTop + story.offsetTop;
      const b = beats[i + 1].offsetTop + story.offsetTop;
      if (y >= b) beat = i + 1;
      else if (y >= a) {
        beat = i + smooth(clamp01((y - a) / (b - a)));
        break;
      }
    }
    return beat;
  }

  let target = scrollBeat();
  let beat = target;
  let pointerX = 0;
  let pointerY = 0;
  let camX = 0;
  let camY = 0;
  const onPointer = (e) => {
    pointerX = (e.clientX / innerWidth - 0.5) * 2;
    pointerY = (e.clientY / innerHeight - 0.5) * 2;
  };
  addEventListener('pointermove', onPointer, { passive: true });

  let last = performance.now();
  let time = 0;
  const born = last;
  let intro = 0;
  let raf = 0;
  let onScreen = true;

  function frame() {
    raf = requestAnimationFrame(frame);
    const now = performance.now();
    const dt = Math.min((now - last) / 1000, 0.25); // Capped, so a stalled tab resumes without a jump.
    last = now;
    time += dt;
    intro = Math.min(1, (now - born) / 1300); // By the wall clock, so a slow GPU still finishes on time.
    target = scrollBeat();
    beat += (target - beat) * (1 - Math.exp(-dt * 7));

    const heroMix = 1 - smooth(clamp01(beat));
    const chapterY = wide ? 0 : VIEW_H * 0.195;
    const heroY = wide ? 0 : -VIEW_H * 0.335;
    rig.position.x = wide ? rtlSign * viewW * mix(0.25, 0.3, heroMix) : 0;
    rig.position.y = mix(chapterY, heroY, heroMix) + (1 - smooth(intro)) * -7;
    const chapterScale = wide ? 1 : 0.615;
    const heroScale = wide ? Math.min(0.84, viewW / 15.5) : 0.36;
    rig.scale.setScalar(mix(chapterScale, heroScale, heroMix));

    phones.forEach((phone, i) => {
      // Carousel: the phone for the current chapter faces front, its neighbours fall away to the sides.
      const a = (i + 1 - beat) * (Math.PI / 2);
      const radius = 3.6;
      // On the copy's side the neighbour stays close to the centre, so it never runs behind the words.
      const sa = Math.sin(a);
      const cx = sa * (wide && sa * rtlSign < 0 ? 1.9 : radius);
      const cz = (Math.cos(a) - 1) * radius;
      const cry = -Math.sin(a) * 0.85 - rtlSign * 0.12 * Math.cos(a);
      const h = HERO[i];
      const bob = Math.sin(time * 0.9 + i * 1.7) * 0.05;
      phone.position.set(mix(cx, h.x * rtlSign, heroMix), mix(0, wide || i % 2 === 0 ? h.y : -0.2, heroMix) + bob, mix(cz, h.z, heroMix));
      phone.rotation.set(Math.sin(time * 0.6 + i) * 0.015, mix(cry, h.ry * rtlSign, heroMix), mix(0, h.rz * rtlSign, heroMix));
    });

    // The pieces orbit outward with the first scroll, and keep turning slowly.
    const drift = wide ? 1 : 0.8;
    disc.position.set(rtlSign * mix(-3.4, -3.5, heroMix) * drift, mix(3.1, 3.4, heroMix), mix(-1.5, -1, heroMix));
    ring.position.set(rtlSign * mix(3.2, 2.9, heroMix) * drift, mix(-3, -3.4, heroMix), mix(-1.5, -0.6, heroMix));
    disc.rotation.set(0.5 + beat * 0.9, time * 0.5 + beat * 1.4, 0);
    ring.rotation.set(-0.5 - beat * 0.9, -time * 0.45 - beat * 1.4, 0);

    camX += (pointerX * 0.45 - camX) * 0.05;
    camY += (-pointerY * 0.3 - camY) * 0.05;
    camera.position.x = wide ? camX : 0;
    camera.position.y = wide ? camY : 0;
    camera.lookAt(0, 0, 0);

    renderer.render(scene, camera);
    if (!root.classList.contains('has-3d')) root.classList.add('has-3d');
  }

  function resume() {
    if (!raf && onScreen && !document.hidden) {
      last = performance.now();
      frame();
    }
  }
  function pause() {
    cancelAnimationFrame(raf);
    raf = 0;
  }

  // Off screen, nothing runs.
  const io = new IntersectionObserver(([entry]) => {
    onScreen = entry.isIntersecting;
    if (onScreen) resume();
    else pause();
  });
  io.observe(story);
  const onVisibility = () => (document.hidden ? pause() : resume());
  document.addEventListener('visibilitychange', onVisibility);

  // Back to the static page when the visitor asks for less motion, or the GPU drops the context.
  const motion = matchMedia('(prefers-reduced-motion: reduce)');
  function teardown() {
    pause();
    io.disconnect();
    ro.disconnect();
    removeEventListener('pointermove', onPointer);
    document.removeEventListener('visibilitychange', onVisibility);
    motion.removeEventListener('change', teardown);
    root.classList.remove('has-3d');
    renderer.dispose();
  }
  motion.addEventListener('change', teardown);
  canvas.addEventListener('webglcontextlost', teardown, { once: true });

  resume();
}

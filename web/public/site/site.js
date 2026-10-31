// Decides whether the 3D scene runs. The page is complete without it: the static screens in the markup stay
// when motion is reduced, WebGL is missing or anything fails. Three.js is fetched only after first paint.
(function () {
  var stage = document.querySelector('[data-stage]');
  if (!stage || !window.matchMedia || matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  var probe = document.createElement('canvas');
  var gl = null;
  try {
    gl = probe.getContext('webgl2') || probe.getContext('webgl');
  } catch (e) {
    gl = null;
  }
  if (!gl) return;
  var lose = gl.getExtension('WEBGL_lose_context');
  if (lose) lose.loseContext();

  function run() {
    import(stage.dataset.scene)
      .then(function (m) { return m.start(stage); })
      .catch(function (e) {
        document.documentElement.classList.remove('has-3d');
        if (window.console) console.warn('Pairly scene stayed static', e);
      });
  }

  function idle() {
    if ('requestIdleCallback' in window) requestIdleCallback(run, { timeout: 1500 });
    else setTimeout(run, 200);
  }

  if (document.readyState === 'complete') idle();
  else addEventListener('load', idle, { once: true });
})();

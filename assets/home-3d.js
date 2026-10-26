/**
 * Colegio Castelgandolfo - Animacion 3D Sutil para Portada Institucional (Three.js)
 * Poliedros geometricos academicos con iluminacion suave y red de particulas conectivas.
 * Movimiento pausado, calmo y distinguido (Sin fatiga visual ni mareo).
 * Aislado estrictamente al contenedor .home-main; Hero superior y textos 100% legibles.
 */

(function () {
  'use strict';

  function initHome3D() {
    var homeMain = document.querySelector('.home-main');
    if (!homeMain) return;

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return;
    }

    if (typeof THREE === 'undefined') {
      return;
    }

    var canvas = document.getElementById('home-canvas-3d');
    if (!canvas) {
      canvas = document.createElement('canvas');
      canvas.id = 'home-canvas-3d';
      canvas.setAttribute('aria-hidden', 'true');
      homeMain.insertBefore(canvas, homeMain.firstChild);
    }

    var width = homeMain.clientWidth || window.innerWidth;
    var height = homeMain.clientHeight || 800;

    var scene, camera, renderer;
    var mainGroup, polyGroup;
    var particlesMesh, linesMesh;
    var particlePositions, particleData = [];
    var pCount = Math.min(90, Math.max(45, Math.floor(width / 20)));
    var maxDistance = 78;

    var mouseX = 0, mouseY = 0;
    var targetX = 0, targetY = 0;
    var isVisible = true;
    var isTabActive = true;
    var time = 0;

    var polyhedra = [];

    try {
      scene = new THREE.Scene();
      camera = new THREE.PerspectiveCamera(45, width / height, 1, 1000);
      camera.position.z = 175;

      renderer = new THREE.WebGLRenderer({
        canvas: canvas,
        alpha: true,
        antialias: true,
        powerPreference: 'low-power'
      });
      renderer.setSize(width, height);
      renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));

      // Iluminacion suave para volumen y brillo especular en caras
      var ambLight = new THREE.AmbientLight(0xffffff, 0.85);
      scene.add(ambLight);

      var dirLight1 = new THREE.DirectionalLight(0xffffff, 0.65);
      dirLight1.position.set(180, 220, 160);
      scene.add(dirLight1);

      var dirLight2 = new THREE.DirectionalLight(0x93c5fd, 0.40);
      dirLight2.position.set(-180, -150, 100);
      scene.add(dirLight2);

      mainGroup = new THREE.Group();
      scene.add(mainGroup);

      polyGroup = new THREE.Group();
      mainGroup.add(polyGroup);

      // Paleta institucional canonica con alto contraste estelar
      var colNavy = new THREE.Color(0x0f2c4d);
      var colGreen = new THREE.Color(0x167044);
      var colGold = new THREE.Color(0xe5a932);
      var colSky = new THREE.Color(0x2563eb);

      // Creador de poliedros de doble capa: facetas semitransparentes + aristas nitidas
      function createAcademicPolyhedron(geometry, colorHex, initialPos, scaleVal, rotSpeed, floatSpeed, floatOffset) {
        var group = new THREE.Group();

        // 1. Capa de facetas translucidas con sombreado plano
        var faceMat = new THREE.MeshLambertMaterial({
          color: colorHex,
          transparent: true,
          opacity: 0.18,
          side: THREE.DoubleSide
        });
        var faceMesh = new THREE.Mesh(geometry, faceMat);
        group.add(faceMesh);

        // 2. Capa de aristas de precision geometrica (EdgesGeometry evita diagonales redundantes)
        var edges = new THREE.EdgesGeometry(geometry);
        var edgeMat = new THREE.LineBasicMaterial({
          color: colorHex,
          transparent: true,
          opacity: 0.55,
          linewidth: 1
        });
        var edgeLines = new THREE.LineSegments(edges, edgeMat);
        group.add(edgeLines);

        group.position.set(initialPos.x, initialPos.y, initialPos.z);
        group.scale.set(scaleVal, scaleVal, scaleVal);
        polyGroup.add(group);

        polyhedra.push({
          mesh: group,
          basePos: { x: initialPos.x, y: initialPos.y, z: initialPos.z },
          rotSpeed: rotSpeed,
          floatSpeed: floatSpeed,
          floatOffset: floatOffset
        });
      }

      // 1. Dodecaedro (Dorado / Matematica & Sabiduria)
      createAcademicPolyhedron(
        new THREE.DodecahedronGeometry(19, 0),
        0xe5a932,
        { x: -width * 0.20, y: height * 0.12, z: -25 },
        1.0,
        { x: 0.0005, y: 0.0007, z: 0.0003 },
        0.0009,
        0
      );

      // 2. Icosaedro (Azul Marino / Ciencia & Exploracion)
      createAcademicPolyhedron(
        new THREE.IcosahedronGeometry(23, 0),
        0x0f2c4d,
        { x: width * 0.22, y: -height * 0.08, z: -15 },
        1.0,
        { x: -0.0004, y: 0.0006, z: 0.0004 },
        0.0008,
        Math.PI * 0.6
      );

      // 3. Octaedro (Verde Esmeralda / Convivencia & Equilibrio)
      createAcademicPolyhedron(
        new THREE.OctahedronGeometry(15, 0),
        0x167044,
        { x: width * 0.04, y: height * 0.24, z: -35 },
        1.0,
        { x: 0.0006, y: -0.0005, z: 0.0005 },
        0.0011,
        Math.PI * 1.2
      );

      // 4. Tetraedro / Prisma Sutil (Cian / Humanidades & Comunidad)
      createAcademicPolyhedron(
        new THREE.TetrahedronGeometry(16, 0),
        0x2563eb,
        { x: -width * 0.08, y: -height * 0.20, z: -40 },
        1.0,
        { x: 0.0004, y: 0.0005, z: -0.0004 },
        0.0007,
        Math.PI * 1.8
      );

      // Red de Particulas (Constelacion de Conocimiento)
      particlePositions = new Float32Array(pCount * 3);
      var particleColors = new Float32Array(pCount * 3);

      for (var i = 0; i < pCount; i++) {
        var rx = (Math.random() - 0.5) * (width * 0.88);
        var ry = (Math.random() - 0.5) * (height * 0.88);
        var rz = (Math.random() - 0.5) * 85;

        particlePositions[i * 3] = rx;
        particlePositions[i * 3 + 1] = ry;
        particlePositions[i * 3 + 2] = rz;

        particleData.push({
          vx: (Math.random() - 0.5) * 0.028,
          vy: (Math.random() - 0.5) * 0.028,
          vz: (Math.random() - 0.5) * 0.018,
          baseY: ry
        });

        var mod = i % 4;
        var c = mod === 0 ? colNavy : (mod === 1 ? colGreen : (mod === 2 ? colGold : colSky));
        particleColors[i * 3] = c.r;
        particleColors[i * 3 + 1] = c.g;
        particleColors[i * 3 + 2] = c.b;
      }

      var pGeo = new THREE.BufferGeometry();
      pGeo.setAttribute('position', new THREE.BufferAttribute(particlePositions, 3).setUsage(THREE.DynamicDrawUsage));
      pGeo.setAttribute('color', new THREE.BufferAttribute(particleColors, 3));

      var pMat = new THREE.PointsMaterial({
        size: 4.8,
        vertexColors: true,
        transparent: true,
        opacity: 0.85,
        blending: THREE.NormalBlending
      });
      particlesMesh = new THREE.Points(pGeo, pMat);
      mainGroup.add(particlesMesh);

      // Segmentos de conexion entre nodos cercanos
      var maxLines = (pCount * (pCount - 1)) / 2;
      var linePositions = new Float32Array(maxLines * 6);
      var lineColors = new Float32Array(maxLines * 6);

      var lineGeo = new THREE.BufferGeometry();
      lineGeo.setAttribute('position', new THREE.BufferAttribute(linePositions, 3).setUsage(THREE.DynamicDrawUsage));
      lineGeo.setAttribute('color', new THREE.BufferAttribute(lineColors, 3).setUsage(THREE.DynamicDrawUsage));

      var lineMat = new THREE.LineBasicMaterial({
        vertexColors: true,
        transparent: true,
        opacity: 0.35,
        blending: THREE.NormalBlending
      });
      linesMesh = new THREE.LineSegments(lineGeo, lineMat);
      mainGroup.add(linesMesh);
      mainGroup.add(linesMesh);

      // Interaccion con el cursor / touch (suave y amortiguada)
      function onPointerMove(e) {
        var clientX = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
        var clientY = e.clientY || (e.touches && e.touches[0] ? e.touches[0].clientY : 0);
        mouseX = (clientX / window.innerWidth) * 2 - 1;
        mouseY = -(clientY / window.innerHeight) * 2 + 1;
      }

      window.addEventListener('pointermove', onPointerMove, { passive: true });

      function handleResize() {
        if (!homeMain) return;
        var w = homeMain.clientWidth || window.innerWidth;
        var h = homeMain.clientHeight || 800;
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h);
      }
      window.addEventListener('resize', handleResize, { passive: true });

      document.addEventListener('visibilitychange', function () {
        isTabActive = !document.hidden;
      });

      if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            isVisible = entry.isIntersecting;
          });
        }, { threshold: 0.05 });
        observer.observe(homeMain);
      }

      // Bucle de animacion continuo con suavizado inercial
      function animate() {
        requestAnimationFrame(animate);
        if (!isTabActive || !isVisible) return;

        time += 0.012;

        // Amortiguacion del cursor (lerp exponencial)
        targetX += (mouseX - targetX) * 0.025;
        targetY += (mouseY - targetY) * 0.025;

        // Rotacion y balanceo muy sutil del conjunto
        mainGroup.rotation.y = targetX * 0.045 + Math.sin(time * 0.15) * 0.015;
        mainGroup.rotation.x = targetY * 0.035;

        // Movimiento armónico de poliedros
        for (var p = 0; p < polyhedra.length; p++) {
          var item = polyhedra[p];
          item.mesh.rotation.x += item.rotSpeed.x;
          item.mesh.rotation.y += item.rotSpeed.y;
          item.mesh.rotation.z += item.rotSpeed.z;

          // Flotacion sinusoidal suave en Y y X
          var wave = Math.sin(time * item.floatSpeed * 30 + item.floatOffset);
          var waveX = Math.cos(time * item.floatSpeed * 22 + item.floatOffset);
          item.mesh.position.y = item.basePos.y + wave * 9;
          item.mesh.position.x = item.basePos.x + waveX * 6;
        }

        // Actualizacion de particulas y conexiones
        var lineVertexPos = 0;
        var lineColorsPos = 0;
        var connectedLines = 0;

        var halfW = width * 0.46;
        var halfH = height * 0.46;

        for (var i = 0; i < pCount; i++) {
          var i3 = i * 3;
          var pt = particleData[i];

          particlePositions[i3] += pt.vx;
          particlePositions[i3 + 1] += pt.vy;
          particlePositions[i3 + 2] += pt.vz;

          // Rebote suave en limites
          if (particlePositions[i3] < -halfW || particlePositions[i3] > halfW) pt.vx *= -1;
          if (particlePositions[i3 + 1] < -halfH || particlePositions[i3 + 1] > halfH) pt.vy *= -1;
          if (particlePositions[i3 + 2] < -55 || particlePositions[i3 + 2] > 55) pt.vz *= -1;

          for (var j = i + 1; j < pCount; j++) {
            var j3 = j * 3;
            var dx = particlePositions[i3] - particlePositions[j3];
            var dy = particlePositions[i3 + 1] - particlePositions[j3 + 1];
            var dz = particlePositions[i3 + 2] - particlePositions[j3 + 2];
            var d = Math.sqrt(dx * dx + dy * dy + dz * dz);

            if (d < maxDistance) {
              var alpha = (1.0 - d / maxDistance) * 0.32;

              linePositions[lineVertexPos++] = particlePositions[i3];
              linePositions[lineVertexPos++] = particlePositions[i3 + 1];
              linePositions[lineVertexPos++] = particlePositions[i3 + 2];

              linePositions[lineVertexPos++] = particlePositions[j3];
              linePositions[lineVertexPos++] = particlePositions[j3 + 1];
              linePositions[lineVertexPos++] = particlePositions[j3 + 2];

              var r = 0.09 * (1 - alpha) + 0.84 * alpha;
              var g = 0.22 * (1 - alpha) + 0.67 * alpha;
              var b = 0.36 * (1 - alpha) + 0.26 * alpha;

              lineColors[lineColorsPos++] = r * alpha;
              lineColors[lineColorsPos++] = g * alpha;
              lineColors[lineColorsPos++] = b * alpha;

              lineColors[lineColorsPos++] = r * alpha;
              lineColors[lineColorsPos++] = g * alpha;
              lineColors[lineColorsPos++] = b * alpha;

              connectedLines++;
            }
          }
        }

        pGeo.attributes.position.needsUpdate = true;
        lineGeo.setDrawRange(0, connectedLines * 2);
        lineGeo.attributes.position.needsUpdate = true;
        lineGeo.attributes.color.needsUpdate = true;

        renderer.render(scene, camera);
      }

      animate();
    } catch (err) {
      console.warn('Error inicializando 3D en home-main:', err);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHome3D);
  } else {
    initHome3D();
  }
})();

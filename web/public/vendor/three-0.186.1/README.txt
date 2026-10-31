three@0.186.1 from npm (npm pack three@0.186.1). The package no longer ships minified builds, so build/three.core.js
and build/three.module.js were minified, unchanged otherwise, with esbuild@0.25.0:
  esbuild three.core.js --minify --format=esm --legal-comments=none --outfile=three.core.min.js
  esbuild three.module.js --minify --format=esm --legal-comments=none --outfile=three.module.min.js
and the import in three.module.min.js repointed from ./three.core.js to ./three.core.min.js. LICENSE is the package's.

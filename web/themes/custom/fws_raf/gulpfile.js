import gulp from 'gulp';
import * as sass from 'sass';
import gulpSass from 'gulp-sass';
import sourcemaps from 'gulp-sourcemaps';
import autoprefixer from 'gulp-autoprefixer';
import cleanCSS from 'gulp-clean-css';
import browserSync from 'browser-sync';

const sassCompiler = gulpSass(sass);
const bs = browserSync.create();

// Default settings
const src = {
    root: './styles',
    scss: './styles/scss/**/*.scss',
    css: './styles/css'
};

// Compile SCSS to CSS
function compileSass() {
    return gulp.src(src.scss)
        .pipe(sourcemaps.init())
        .pipe(sassCompiler.sync({
             outputStyle: 'expanded',
             silenceDeprecations: ['legacy-js-api']
         }).on('error', sassCompiler.logError))
        .pipe(autoprefixer({
            cascade: false
        }))
        .pipe(sourcemaps.write('./maps'))
        .pipe(gulp.dest(src.css))
        .pipe(bs.stream());
}

// Minify CSS
function minifyCSS() {
    return gulp.src(src.css + '/*.css')
        .pipe(cleanCSS())
        .pipe(gulp.dest(src.css + '/min'));
}

// Watch task
function watchFiles() {
    bs.init({
        server: {
            baseDir: './',
            directory: true
        },
        notify: false,
        open: false
    });

    gulp.watch(src.scss, { ignoreInitial: false }, compileSass);
}

// Define tasks
const build = gulp.series(compileSass);
const watch = gulp.series(compileSass, watchFiles);

// Export tasks
export {
    build,
    watch,
    minifyCSS as minify,
    watch as default
};

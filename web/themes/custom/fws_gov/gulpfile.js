'use strict';

// The streaming build system.
var gulp = require('gulp');

// Gulp plugin for sass.
var sass = require('gulp-sass');

// // Source map support for Gulp.js.
var sourcemaps = require('gulp-sourcemaps');

// Prefix CSS.
var autoprefixer = require('gulp-autoprefixer');

// Prevent pipe breaking caused by errors from gulp plugins.
var plumber = require('gulp-plumber');

// Live CSS Reload & Browser Syncing.
var browserSync = require('browser-sync').create();

// Livereload.
var livereload = require('gulp-livereload');

// Allows you to use glob syntax in imports (i.e. @import "dir/*.sass"). Use as a custom importer for node-sass.
var importer = require('node-sass-globbing');

// Default settings.
var src = {
  root: './styles',
  scss: './styles/scss/**/*.*',
  css: './styles/css'
};

// Define list of vendors.
var _vendors = [
  './node_modules/breakpoint-sass/stylesheets/',
  './node_modules/compass-mixins/lib/'
];

// List of browsers. Opera 15 for prefix -webkit.
var browsers = ['last 3 versions'];

// For developers. Contain better outputStyle for reading.
gulp.task('dev', function () {
  gulp.src(src.scss)
    .pipe(plumber())
    .pipe(sourcemaps.init())
    .pipe(sass({
      importer: importer,
      includePaths: _vendors,
      outputStyle: 'expanded'
    }).on('error', sass.logError))
    .pipe(autoprefixer({
      browsers: browsers
    }))
    .pipe(sourcemaps.write('.'))
    .pipe(gulp.dest(src.css))
    .pipe(livereload());
});

// Minifed css styles.
gulp.task('prod', function () {
  gulp.src(src.scss)
    .pipe(plumber())
    .pipe(sass({
      importer: importer,
      includePaths: _vendors,
      outputStyle: 'compressed'
    }).on('error', sass.logError))
    .pipe(autoprefixer({
      browsers: browsers,
      cascade: false
    }))
    // .pipe(rename({suffix: '.min'}))
    .pipe(gulp.dest(src.css));
});

// Watch task.
gulp.task('watch', function () {
  gulp.watch(src.scss, ['dev']);
});

// Default task.
gulp.task('default', ['dev', 'watch'], function () {
  livereload.listen();
});

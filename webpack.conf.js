const webpack = require('webpack');
const path = require('path');
const exec = require('child_process').execSync;
const UglifyJsPlugin = require('uglifyjs-webpack-plugin');
const isProd = process.env.NODE_ENV === 'production';
const pwd = exec('pwd').toString();
const adminPath = process.env.GRAV_ADMIN_PATH || path.resolve(pwd + '/../admin/themes/grav/app');

module.exports = {
    entry: {
        app: './app/main.js'
    },
    devtool: isProd ? false : 'eval-source-map',
    target: 'web',
    resolve: {
        alias: {
            admin: adminPath
        }
    },
    output: {
        path: path.resolve(__dirname, 'js'),
        filename: '[name].js',
        chunkFilename: 'vendor.js'
    },
    optimization: {
        minimize: isProd,
        minimizer: [
            new UglifyJsPlugin({
                uglifyOptions: {
                    compress: {
                        drop_console: true
                    },
                    dead_code: true
                }
            })
        ],
        splitChunks: {
            cacheGroups: {
                vendors: {
                    test: /[\\/]node_modules[\\/]/,
                    priority: 1,
                    name: 'vendor',
                    enforce: true,
                    chunks: 'all'
                }
            }
        }
    },
    plugins: [
        new webpack.ProvidePlugin({
            'fetch': 'imports-loader?this=>global!exports-loader?global.fetch!whatwg-fetch'
        })
    ],
    externals: {
        jquery: 'jQuery',
        'git-sync': 'GitSync',
        'grav-config': 'GravAdmin'
    },
    module: {
        rules: [
            { enforce: 'pre', test: /\.json$/, loader: 'json-loader' },
            { enforce: 'pre', test: /\.js$/, loader: 'eslint-loader', exclude: /node_modules/ },
            { test: /\.css$/, loader: 'style-loader!css-loader' },
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: ['@babel/preset-env'],
                        plugins: ['@babel/plugin-proposal-object-rest-spread']
                    }
                }
            }
        ]
    }
};

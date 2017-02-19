var webpack   = require('webpack'),
    exec      = require('child_process').execSync,
    path      = require('path'),
    pwd       = exec('pwd').toString(),
    adminPath = path.resolve(pwd + '/../admin/themes/grav/app');

module.exports = {
    entry: './app/main.js',
    devtool: 'source-map',
    output: {
        path: path.resolve(__dirname, 'js'),
        filename: 'app.js'
    },
    resolve: {
        alias: {
            admin: adminPath
        }
    },
    plugins: [
        new webpack.ProvidePlugin({
            'fetch': 'imports?this=>global!exports?global.fetch!whatwg-fetch'
        }),
        new webpack.optimize.UglifyJsPlugin({
            compress: { warnings: false },
            output: { comments: false, semicolons: true }
        })
    ],
    externals: {
        jquery: 'jQuery',
        'git-sync': 'GitSync',
        'grav-config': 'GravAdmin'
    },
    module: {
        preLoaders: [
            { test: /\.json$/, loader: 'json' },
            { test: /\.js$/, loader: 'eslint', exclude: [/node_modules/, /js/] }
        ],
        loaders: [
            { test: /\.css$/, loader: 'style-loader!css-loader' },
            {
                test: /\.js$/,
                loader: 'babel-loader',
                exclude: [/node_modules/, /vex-js/],
                query: {
                    presets: ['es2015', 'stage-3']
                }
            }
        ]
    }
};

# router

psrphp router

## 特性

* 支持绑定中间件
* 支持参数绑定
* 支持分组路由
* 支持正则路由

## 代码示例

``` php

$router = new Router();

$router->addRoute(['GET'], '/path1/{id:\d+}', 'somehandler1');
$router->addRoute(['GET'], '/path2[/{id:\d+}]', 'somehandler2');
$router->addGroup('/group', function (Router $router) {
    $router->addRoute(['GET'], '/sub1', 'otherhandler1');
    $router->addRoute(['GET'], '/sub2', 'otherhandler2', 'name1', ['middleware3']);
    $router->addRoute(['GET'], '/sub3/{id:\d+}', 'otherhandler3', 'name2', ['middleware3']);
}, ['somemiddleware1', 'somemiddleware2'], ['q'=>'111']);

$router->dispatch('GET', '/path2/33');
// Array
// (
//     [0] => 1
//     [1] => somehandler2
//     [2] => Array
//         (
//             [id] => 33
//         )

//     [3] => Array
//         (
//         )

//     [4] => Array
//         (
//         )

// )

$router->dispatch('GET', '/group/sub1');
// Array
// (
//     [0] => 1
//     [1] => otherhandler1
//     [2] => Array
//         (
//         )

//     [3] => Array
//         (
//             [0] => somemiddleware1
//             [1] => somemiddleware2
//         )

//     [4] => Array
//         (
//             [q] => 111
//         )

// )

$router->dispatch('GET', '/group/sub2');
// Array
// (
//     [0] => 1
//     [1] => otherhandler2
//     [2] => Array
//         (
//         )

//     [3] => Array
//         (
//             [0] => middleware3
//             [1] => somemiddleware1
//             [2] => somemiddleware2
//         )

//     [4] => Array
//         (
//             [q] => 111
//         )

// )

$router->dispatch('GET', '/group/sub3/11');
// Array
// (
//     [0] => 1
//     [1] => otherhandler3
//     [2] => Array
//         (
//             [id] => 11
//         )

//     [3] => Array
//         (
//             [0] => middleware3
//             [1] => somemiddleware1
//             [2] => somemiddleware2
//         )

//     [4] => Array
//         (
//             [q] => 111
//         )

// )

$url = $router->build('name2', ['id' => 11]);
// /group/sub3/11
```

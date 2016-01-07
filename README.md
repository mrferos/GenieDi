# GenieDI
<center><small>Resolving all your wishes</small></center>

GenieDI is another PHP DI Container. Why? For fun!

<img src="http://i.imgur.com/Qvzkycp.jpg" alt="Saitama" width=200 height=200 />

## How to use it?
Registering a simple service
```php
$genie = new GenieDi\Container(true);
$genie->register(Foo::class);
return $genie->get(Foo::class); // will return an instance of Foo
```
Register a service with a name
```php
$genie = new GenieDi\Container(true);
$genie->register('foobaz', Foo::class);
return $genie->get('foobaz'); // will return an instance of Foo
```
Register a service using a factory to instantiate the object
```php
$genie = new GenieDi\Container(true);
$genie->register('foobaz', function(ContainerInterop $container) {
	return new Foo($container->get('other-dep');
});

// bit of a caveat here, the register method doesn't recognize a class with an __invoke method as a factory, so instead we can do this:
$genie->registerFactory('foobaz', FooBazFactory::class);

return $genie->get('foobaz'); // will return an instance of Foo
```

## More documentation to come! (and tests!)
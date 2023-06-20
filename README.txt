1) we need to use repository pattern with interfaces & repository classes that will do eloquent queries via models,
inject interface in constructor that will call the methods to get or modify data from database.
2) divide the basis logics into separate methods & call them into a required methods,
 to achieve this in proper way we need to create separate service classes for each functionality.
3) strictly Follow DRY & focus on Solid Principals.
4) try use best names for variables & methods for better readability & use camelCase instead of Snake Case (it looks pretty, reading friendly)


Note:
I've not refactored the full BookingController for now (because of time) but I've shared example of mine code writing standard that I'm using on a daily basis,
I'm using Repository Pattern and bind interfaces with repository classes using laravel service container, also try to follow Solid Principles.
instead of writing all code in class & one method, try to split in multiple chunks of code & use them.
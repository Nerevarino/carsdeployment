create table if not exists "Cars" (
    id serial primary key,
    brand text not null,
    model text not null,
    power text not null,
    photo text not null,
    location text not null  
);

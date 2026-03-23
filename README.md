# 🗡️ The Most Careless DBAL
**A hymn to simplicity, laziness, and brutal performance.**

This is the memory of a warrior that operated in the shadows. 
Born from the depths of ZendFramework 0.7 and distilled to its purest form. 
In silence, this pattern handled hundreds+ of millions of rows, iterated through hordes of copies, and joined what purists said could not be joined. 
It generated the darkest of SQL queries... and surprisingly, they flew.

## Features
- Ninja Active Record: Minimal mapping. Convention over configuration (meaning: align your database fields with the table and shut up).
- Zero Friction: Designed to write fast, execute faster, and stop overthinking objects.
- Dark Performance: A particular commitment to OOP. We prioritize the freedom of the query to make precise data fly (sometimes). It embraces hybrid scenarios and custom projections (if you know what you are doing).
- Includes: a kit for input validation.

*"Use it at your own risk. Or don't. It doesn't care, it has already survived deadlier battles than your startup."*

# 🚧 Disclaimer

Fortunately/Unfortunately, this is an W.I.P. AI-enhanced simplification of the original pattern. 




# Usage


```
use ar\db;

db::init('localhost', 'root', 'password', 'warrior_db');

// Align your fields and shut up.
class Post extends ar { const TBL = 'posts'; }

// INSERT INTO `posts` (`title`, `status`) VALUES (?, ?)
$p = new Post();
$p->title = "Shadow Warrior";
$p->status = "draft";
$p->save(); 

// UPDATE `posts` SET `status` = ? WHERE `id` = ?
$p->status = "published";
$p->save();

// SELECT * FROM `posts` WHERE status = ? AND id IN (?,?,?) ORDER BY id DESC LIMIT 10
$list = Post::sel()
    ->where("status = ?", "published")
    ->where("id IN (?)", [1, 2, 3])
    ->order("id DESC")
    ->limit(10)
    ->fetch();

// SELECT * FROM `comments` WHERE `fk_posts` = ?
$comments = $p->rels(Comment::class)->fetch(); 

// SELECT COUNT(1) as c FROM `posts` WHERE status = ?
$total = Post::sel()->where("status = ?", "published")->count();

// SELECT * FROM `posts` WHERE id IN (SELECT fk_posts FROM `logs` WHERE type = ?)
$sub = Log::sel()->select('fk_posts')->where('type = ?', 'critical');
$criticalPosts = Post::sel()->where("id IN (?)", $sub)->fetch();

// DELETE FROM `posts` WHERE `id` = ?
$p->del();
``` 

---

AI thingys
# Project Structure

- src/ar.php
- README.md this file



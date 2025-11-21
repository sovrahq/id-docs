import { IsEmail, IsString, MinLength, IsNotEmpty } from 'class-validator';

export class CreateUserDto {
  @IsEmail()
  email: string;

  @IsString()
  @MinLength(6)
  password: string;

  @IsString()
  @IsNotEmpty()
  @MinLength(1)
  first_name: string;

  @IsString()  
  @IsNotEmpty()
  @MinLength(1)
  last_name: string;
}
